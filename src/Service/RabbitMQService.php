<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InboxMessage;
use App\Entity\OutboxMessage;
use App\Entity\Product;
use App\Message\OrderCreatedMessage;
use App\Message\OrderProcessingStatusMessage;
use App\Message\ProductUpdatedMessage;
use App\Repository\InboxMessageRepository;
use App\Repository\OutboxMessageRepository;
use App\Repository\ProductRepository;
use App\Service\Api\IntegrationEventLoggerServiceInterface;
use App\Service\Api\RabbitMQServiceInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

final class RabbitMQService implements RabbitMQServiceInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ProductRepository $productRepository,
        private readonly InboxMessageRepository $inboxMessageRepository,
        private readonly OutboxMessageRepository $outboxMessageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly IntegrationEventLoggerServiceInterface $integrationEventLogger,
    ) {
    }

    public function productUpdated(Product $product): string
    {
        $message = new ProductUpdatedMessage(
            Uuid::v7()->toRfc4122(),
            self::MESSAGE_TYPE_PRODUCT_UPDATED,
            new DateTimeImmutable(),
            $product->getId(),
            $product->getName(),
            $product->getPrice(),
            $product->getQuantity(),
            $product->getVersion(),
        );

        $this->entityManager->persist(
            $this->createOutboxMessage(
                $message->eventId,
                $this->normalizeProductUpdatedMessage($message),
                $product->getId(),
                $message->type,
                $message->createdAt,
            )
        );

        return $message->eventId;
    }

    public function orderCreated(OrderCreatedMessage $message): void
    {
        if ($this->inboxMessageRepository->find($message->eventId) !== null) {
            return;
        }

        $this->assertOrderCreatedMessageIsValid($message);

        $product = $this->productRepository->find($message->productId);

        if ($product === null) {
            $this->storeInboxResult($message, null, self::INBOX_STATUS_FAILED);
            $statusEventId = $this->storeOrderProcessingStatus(
                $message,
                self::ORDER_STATUS_FAILED,
                sprintf('Product "%s" was not found while applying order.created event.', $message->productId),
            );
            $this->entityManager->flush();
            $this->publishOutboxMessage($statusEventId);

            return;
        }

        if ($this->isOldOrderMessage($product, $message)) {
            $this->storeInboxResult($message, $product->getId(), self::INBOX_STATUS_IGNORED);
            $statusEventId = $this->storeOrderProcessingStatus(
                $message,
                self::ORDER_STATUS_FAILED,
                'Stale order event received.',
            );
            $productUpdatedEventId = $this->productUpdated($product);
            $this->entityManager->flush();
            $this->publishOutboxMessage($statusEventId);
            $this->publishOutboxMessage($productUpdatedEventId);

            return;
        }

        if ($message->expectedProductVersion !== $product->getVersion()) {
            $this->storeInboxResult($message, $product->getId(), self::INBOX_STATUS_FAILED);
            $statusEventId = $this->storeOrderProcessingStatus(
                $message,
                self::ORDER_STATUS_FAILED,
                sprintf(
                    'Product version mismatch. Expected version %d, actual version %d.',
                    $message->expectedProductVersion,
                    $product->getVersion(),
                ),
            );
            $productUpdatedEventId = $this->productUpdated($product);
            $this->entityManager->flush();
            $this->publishOutboxMessage($statusEventId);
            $this->publishOutboxMessage($productUpdatedEventId);

            return;
        }

        try {
            $this->reserveProductQuantity($product, $message->quantityOrdered);
        } catch (InvalidArgumentException $exception) {
            $this->storeInboxResult($message, $product->getId(), self::INBOX_STATUS_FAILED);
            $statusEventId = $this->storeOrderProcessingStatus(
                $message,
                self::ORDER_STATUS_FAILED,
                $exception->getMessage(),
            );
            $productUpdatedEventId = $this->productUpdated($product);
            $this->entityManager->flush();
            $this->publishOutboxMessage($statusEventId);
            $this->publishOutboxMessage($productUpdatedEventId);

            return;
        }

        $product->setLastOrderEventAt($message->createdAt);
        $this->storeInboxResult($message, $product->getId(), self::INBOX_STATUS_PROCESSED);
        $statusEventId = $this->storeOrderProcessingStatus($message, self::ORDER_STATUS_PROCESSED);
        $productUpdatedEventId = $this->productUpdated($product);
        $this->entityManager->flush();
        $this->publishOutboxMessage($statusEventId);
        $this->publishOutboxMessage($productUpdatedEventId);
    }

    public function publishOutboxMessage(string $eventId): bool
    {
        $outboxMessage = $this->outboxMessageRepository->find($eventId);

        if (!$outboxMessage instanceof OutboxMessage || $outboxMessage->getStatus() !== self::OUTBOX_STATUS_CREATED) {
            return false;
        }

        return $this->publishMessage($outboxMessage);
    }

    public function publishPendingOutbox(array $createdProducts): int
    {
        $published = 0;

        foreach ($createdProducts as $outboxMessage) {
            if ($this->publishMessage($outboxMessage)) {
                ++$published;
            }
        }

        return $published;
    }

    private function isOldOrderMessage(Product $product, OrderCreatedMessage $message): bool
    {
        $lastOrderEventAt = $product->getLastOrderEventAt();

        return $lastOrderEventAt !== null && $message->createdAt <= $lastOrderEventAt;
    }

    private function storeInboxResult(OrderCreatedMessage $message, ?string $productId, string $status): void
    {
        $inboxMessage = new InboxMessage();
        $inboxMessage->setEventId($message->eventId);
        $inboxMessage->setEvent($this->normalizeOrderCreatedMessage($message));
        $inboxMessage->setProductId($productId);
        $inboxMessage->setEventType($message->type);
        $inboxMessage->setStatus($status);
        $inboxMessage->setCreatedAt($message->createdAt);

        $this->entityManager->persist($inboxMessage);
    }

    private function storeOrderProcessingStatus(
        OrderCreatedMessage $message,
        string $status,
        ?string $error = null,
    ): string {
        $statusMessage = new OrderProcessingStatusMessage(
            Uuid::v7()->toRfc4122(),
            self::MESSAGE_TYPE_ORDER_PROCESSING_STATUS,
            new DateTimeImmutable(),
            $message->orderId,
            $message->eventId,
            $message->productId,
            $status,
            $error,
        );

        $this->entityManager->persist(
            $this->createOutboxMessage(
                $statusMessage->eventId,
                $this->normalizeOrderProcessingStatusMessage($statusMessage),
                $message->productId,
                $statusMessage->type,
                $statusMessage->createdAt,
            )
        );

        return $statusMessage->eventId;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function normalizeProductUpdatedMessage(ProductUpdatedMessage $message): array
    {
        return [
            'eventId'   => $message->eventId,
            'type'      => $message->type,
            'createdAt' => $message->createdAt->format(DATE_ATOM),
            'id'        => $message->id,
            'name'      => $message->name,
            'price'     => $message->price,
            'quantity'  => $message->quantity,
            'version'   => $message->version,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function normalizeOrderCreatedMessage(OrderCreatedMessage $message): array
    {
        return [
            'eventId'                => $message->eventId,
            'type'                   => $message->type,
            'createdAt'              => $message->createdAt->format(DATE_ATOM),
            'orderId'                => $message->orderId,
            'productId'              => $message->productId,
            'quantityOrdered'        => $message->quantityOrdered,
            'expectedProductVersion' => $message->expectedProductVersion,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function normalizeOrderProcessingStatusMessage(OrderProcessingStatusMessage $message): array
    {
        return [
            'eventId'      => $message->eventId,
            'type'         => $message->type,
            'createdAt'    => $message->createdAt->format(DATE_ATOM),
            'orderId'      => $message->orderId,
            'orderEventId' => $message->orderEventId,
            'productId'    => $message->productId,
            'status'       => $message->status,
            'error'        => $message->error,
        ];
    }

    /**
     * @throws Exception
     */
    private function denormalizeOutboxMessage(OutboxMessage $outboxMessage): object
    {
        $event = $outboxMessage->getEvent();

        return match ($outboxMessage->getEventType()) {
            self::MESSAGE_TYPE_PRODUCT_UPDATED => new ProductUpdatedMessage(
                $this->requireString($event, 'eventId'),
                $this->requireString($event, 'type'),
                $this->requireDateTime($event, 'createdAt'),
                $this->requireString($event, 'id'),
                $this->requireString($event, 'name'),
                $this->requireFloat($event, 'price'),
                $this->requireInt($event, 'quantity'),
                $this->requireInt($event, 'version'),
            ),
            self::MESSAGE_TYPE_ORDER_PROCESSING_STATUS => new OrderProcessingStatusMessage(
                $this->requireString($event, 'eventId'),
                $this->requireString($event, 'type'),
                $this->requireDateTime($event, 'createdAt'),
                $this->requireString($event, 'orderId'),
                $this->requireString($event, 'orderEventId'),
                $this->requireString($event, 'productId'),
                $this->requireString($event, 'status'),
                $this->optionalString($event, 'error'),
            ),
            default => throw new LogicException(
                sprintf('Unsupported outbox event type "%s".', $outboxMessage->getEventType())
            ),
        };
    }

    private function publishMessage(OutboxMessage $outboxMessage): bool
    {
        try {
            $this->messageBus->dispatch($this->denormalizeOutboxMessage($outboxMessage));
            $outboxMessage->setStatus(self::OUTBOX_STATUS_PUBLISHED);
            $this->entityManager->flush();

            return true;
        } catch (Throwable $exception) {
            $this->integrationEventLogger->warning(
                'Outbox event publication failed and will be retried later.',
                [
                    'eventId'   => $outboxMessage->getEventId(),
                    'eventType' => $outboxMessage->getEventType(),
                    'error'     => $exception->getMessage(),
                ],
            );

            return false;
        }
    }

    private function assertOrderCreatedMessageIsValid(OrderCreatedMessage $message): void
    {
        if ($message->quantityOrdered <= 0) {
            throw new UnrecoverableMessageHandlingException('Ordered quantity must be greater than zero.');
        }

        if ($message->expectedProductVersion <= 0) {
            throw new UnrecoverableMessageHandlingException('Expected product version must be greater than zero.');
        }
    }

    private function reserveProductQuantity(Product $product, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Reserved quantity must be greater than zero.');
        }

        if ($quantity > $product->getQuantity()) {
            throw new InvalidArgumentException('Ordered quantity exceeds available stock.');
        }

        $product->setQuantity($product->getQuantity() - $quantity);
        $product->setVersion($product->getVersion() + 1);
    }

    /**
     * @param array<string, scalar|null> $event
     */
    private function requireString(array $event, string $field): string
    {
        $value = $event[$field] ?? null;

        if (!is_string($value) || $value === '') {
            throw new LogicException(sprintf('Field "%s" must be a non-empty string.', $field));
        }

        return $value;
    }

    /**
     * @param array<string, scalar|null> $event
     *
     * @noinspection PhpSameParameterValueInspection
     */
    private function optionalString(array $event, string $field): ?string
    {
        $value = $event[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, scalar|null> $event
     *
     * @noinspection PhpSameParameterValueInspection
     */
    private function requireFloat(array $event, string $field): float
    {
        $value = $event[$field] ?? null;

        if (!is_int($value) && !is_float($value)) {
            throw new LogicException(sprintf('Field "%s" must be numeric.', $field));
        }

        return (float)$value;
    }

    /**
     * @param array<string, scalar|null> $event
     */
    private function requireInt(array $event, string $field): int
    {
        $value = $event[$field] ?? null;

        if (!is_int($value)) {
            throw new LogicException(sprintf('Field "%s" must be an integer.', $field));
        }

        return $value;
    }

    /**
     * @throws Exception
     *
     * @param array<string, scalar|null> $event
     *
     * @noinspection PhpSameParameterValueInspection
     */
    private function requireDateTime(array $event, string $field): DateTimeImmutable
    {
        return new DateTimeImmutable($this->requireString($event, $field));
    }

    /**
     * @param array<string, scalar|null> $event
     */
    private function createOutboxMessage(
        string $eventId,
        array $event,
        ?string $productId,
        string $eventType,
        DateTimeImmutable $createdAt,
    ): OutboxMessage {
        $outboxMessage = new OutboxMessage();
        $outboxMessage->setEventId($eventId);
        $outboxMessage->setEvent($event);
        $outboxMessage->setProductId($productId);
        $outboxMessage->setEventType($eventType);
        $outboxMessage->setStatus(self::OUTBOX_STATUS_CREATED);
        $outboxMessage->setCreatedAt($createdAt);

        return $outboxMessage;
    }
}
