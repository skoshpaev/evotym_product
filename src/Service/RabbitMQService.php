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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class RabbitMQService implements RabbitMQServiceInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ProductRepository $productRepository,
        private readonly InboxMessageRepository $inboxMessageRepository,
        private readonly OutboxMessageRepository $outboxMessageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly IntegrationEventLogger $integrationEventLogger,
    ) {
    }

    public function productUpdated(Product $product): string
    {
        $message = ProductUpdatedMessage::create(
            $product->getId(),
            $product->getName(),
            $product->getPrice(),
            $product->getQuantity(),
            $product->getVersion(),
        );

        $this->entityManager->persist(
            OutboxMessage::create(
                $message->eventId,
                $this->normalizeProductUpdatedMessage($message),
                $product->getId(),
                $message->type,
                $message->createdAt,
            ),
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
            $this->storeInboxResult($message, null, InboxMessage::STATUS_FAILED);
            $statusEventId = $this->storeOrderProcessingStatus(
                $message,
                OrderProcessingStatusMessage::STATUS_FAILED,
                sprintf('Product "%s" was not found while applying order.created event.', $message->productId),
            );
            $this->entityManager->flush();
            $this->publishOutboxMessage($statusEventId);

            return;
        }

        if ($this->isOldOrderMessage($product, $message)) {
            $this->storeInboxResult($message, $product->getId(), InboxMessage::STATUS_IGNORED);
            $statusEventId = $this->storeOrderProcessingStatus(
                $message,
                OrderProcessingStatusMessage::STATUS_FAILED,
                'Stale order event received.',
            );
            $productUpdatedEventId = $this->productUpdated($product);
            $this->entityManager->flush();
            $this->publishOutboxMessage($statusEventId);
            $this->publishOutboxMessage($productUpdatedEventId);

            return;
        }

        if ($message->expectedProductVersion !== $product->getVersion()) {
            $this->storeInboxResult($message, $product->getId(), InboxMessage::STATUS_FAILED);
            $statusEventId = $this->storeOrderProcessingStatus(
                $message,
                OrderProcessingStatusMessage::STATUS_FAILED,
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
            $product->reserveQuantity($message->quantityOrdered);
        } catch (\InvalidArgumentException $exception) {
            $this->storeInboxResult($message, $product->getId(), InboxMessage::STATUS_FAILED);
            $statusEventId = $this->storeOrderProcessingStatus(
                $message,
                OrderProcessingStatusMessage::STATUS_FAILED,
                $exception->getMessage(),
            );
            $productUpdatedEventId = $this->productUpdated($product);
            $this->entityManager->flush();
            $this->publishOutboxMessage($statusEventId);
            $this->publishOutboxMessage($productUpdatedEventId);

            return;
        }

        $product->markOrderEventProcessed($message->createdAt);
        $this->storeInboxResult($message, $product->getId(), InboxMessage::STATUS_PROCESSED);
        $statusEventId = $this->storeOrderProcessingStatus(
            $message,
            OrderProcessingStatusMessage::STATUS_PROCESSED,
        );
        $productUpdatedEventId = $this->productUpdated($product);
        $this->entityManager->flush();
        $this->publishOutboxMessage($statusEventId);
        $this->publishOutboxMessage($productUpdatedEventId);
    }

    public function publishOutboxMessage(string $eventId): bool
    {
        $outboxMessage = $this->outboxMessageRepository->find($eventId);

        if (!$outboxMessage instanceof OutboxMessage || !$outboxMessage->isCreated()) {
            return false;
        }

        return $this->publishMessage($outboxMessage);
    }

    public function publishPendingOutbox(): int
    {
        $published = 0;

        foreach ($this->outboxMessageRepository->findCreatedOrdered() as $outboxMessage) {
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
        $this->entityManager->persist(
            InboxMessage::create(
                $message->eventId,
                $this->normalizeOrderCreatedMessage($message),
                $productId,
                $message->type,
                $status,
                $message->createdAt,
            ),
        );
    }

    private function storeOrderProcessingStatus(
        OrderCreatedMessage $message,
        string $status,
        ?string $error = null,
    ): string {
        $statusMessage = OrderProcessingStatusMessage::create(
            $message->orderId,
            $message->eventId,
            $message->productId,
            $status,
            $error,
        );

        $this->entityManager->persist(
            OutboxMessage::create(
                $statusMessage->eventId,
                $this->normalizeOrderProcessingStatusMessage($statusMessage),
                $message->productId,
                $statusMessage->type,
                $statusMessage->createdAt,
            ),
        );

        return $statusMessage->eventId;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function normalizeProductUpdatedMessage(ProductUpdatedMessage $message): array
    {
        return [
            'eventId' => $message->eventId,
            'type' => $message->type,
            'createdAt' => $message->createdAt->format(DATE_ATOM),
            'id' => $message->id,
            'name' => $message->name,
            'price' => $message->price,
            'quantity' => $message->quantity,
            'version' => $message->version,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function normalizeOrderCreatedMessage(OrderCreatedMessage $message): array
    {
        return [
            'eventId' => $message->eventId,
            'type' => $message->type,
            'createdAt' => $message->createdAt->format(DATE_ATOM),
            'orderId' => $message->orderId,
            'productId' => $message->productId,
            'quantityOrdered' => $message->quantityOrdered,
            'expectedProductVersion' => $message->expectedProductVersion,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function normalizeOrderProcessingStatusMessage(OrderProcessingStatusMessage $message): array
    {
        return [
            'eventId' => $message->eventId,
            'type' => $message->type,
            'createdAt' => $message->createdAt->format(DATE_ATOM),
            'orderId' => $message->orderId,
            'orderEventId' => $message->orderEventId,
            'productId' => $message->productId,
            'status' => $message->status,
            'error' => $message->error,
        ];
    }

    private function denormalizeOutboxMessage(OutboxMessage $outboxMessage): object
    {
        $event = $outboxMessage->getEvent();

        return match ($outboxMessage->getEventType()) {
            ProductUpdatedMessage::TYPE => ProductUpdatedMessage::fromPayload(
                $this->requireString($event, 'id'),
                $this->requireString($event, 'name'),
                $this->requireFloat($event, 'price'),
                $this->requireInt($event, 'quantity'),
                $this->requireInt($event, 'version'),
                $this->requireString($event, 'eventId'),
                $this->requireString($event, 'type'),
                $this->requireDateTime($event, 'createdAt'),
            ),
            OrderProcessingStatusMessage::TYPE => OrderProcessingStatusMessage::fromPayload(
                $this->requireString($event, 'orderId'),
                $this->requireString($event, 'orderEventId'),
                $this->requireString($event, 'productId'),
                $this->requireString($event, 'status'),
                $this->optionalString($event, 'error'),
                $this->requireString($event, 'eventId'),
                $this->requireString($event, 'type'),
                $this->requireDateTime($event, 'createdAt'),
            ),
            default => throw new \LogicException(sprintf('Unsupported outbox event type "%s".', $outboxMessage->getEventType())),
        };
    }

    private function publishMessage(OutboxMessage $outboxMessage): bool
    {
        try {
            $this->messageBus->dispatch($this->denormalizeOutboxMessage($outboxMessage));
            $outboxMessage->markPublished();
            $this->entityManager->flush();

            return true;
        } catch (\Throwable $exception) {
            $this->integrationEventLogger->warning(
                'Outbox event publication failed and will be retried later.',
                [
                    'eventId' => $outboxMessage->getEventId(),
                    'eventType' => $outboxMessage->getEventType(),
                    'error' => $exception->getMessage(),
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

    /**
     * @param array<string, scalar|null> $event
     */
    private function requireString(array $event, string $field): string
    {
        $value = $event[$field] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new \LogicException(sprintf('Field "%s" must be a non-empty string.', $field));
        }

        return $value;
    }

    /**
     * @param array<string, scalar|null> $event
     */
    private function optionalString(array $event, string $field): ?string
    {
        $value = $event[$field] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<string, scalar|null> $event
     */
    private function requireFloat(array $event, string $field): float
    {
        $value = $event[$field] ?? null;

        if (!\is_int($value) && !\is_float($value)) {
            throw new \LogicException(sprintf('Field "%s" must be numeric.', $field));
        }

        return (float) $value;
    }

    /**
     * @param array<string, scalar|null> $event
     */
    private function requireInt(array $event, string $field): int
    {
        $value = $event[$field] ?? null;

        if (!\is_int($value)) {
            throw new \LogicException(sprintf('Field "%s" must be an integer.', $field));
        }

        return $value;
    }

    /**
     * @param array<string, scalar|null> $event
     */
    private function requireDateTime(array $event, string $field): DateTimeImmutable
    {
        return new DateTimeImmutable($this->requireString($event, $field));
    }
}
