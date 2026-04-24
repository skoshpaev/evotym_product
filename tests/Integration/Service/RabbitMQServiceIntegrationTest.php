<?php
/** @noinspection SqlNoDataSourceInspection */

/** @noinspection SqlResolve */

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Product;
use App\Message\OrderCreatedMessage;
use App\Repository\OutboxMessageRepository;
use App\Service\Api\RabbitMQServiceInterface;
use App\Tests\Integration\DatabaseKernelTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\ORMException;
use JsonException;

final class RabbitMQServiceIntegrationTest extends DatabaseKernelTestCase
{
    protected function transportNames(): array
    {
        return ['product_updated', 'order_processing_status', 'order_created', 'order_created_failed'];
    }

    /**
     * @throws Exception
     * @throws ORMException
     */
    public function testOrderCreatedReservesStockAndStoresInboxAndOutboxRecords(): void
    {
        $product = $this->createProduct('019db9fd-5141-783b-804e-3f3d8ab184e7', 'Coffee Mug', 12.99, 5, 1);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $service = self::getContainer()->get(RabbitMQServiceInterface::class);
        $outboxRepository = self::getContainer()->get(OutboxMessageRepository::class);
        $statusTransport = $this->getTransport('order_processing_status');
        $productTransport = $this->getTransport('product_updated');

        assert($service instanceof RabbitMQServiceInterface);
        assert($outboxRepository instanceof OutboxMessageRepository);

        $message = new OrderCreatedMessage(
            '019db9fd-aaaa-7703-8f00-a27ee9795ff9',
            RabbitMQServiceInterface::MESSAGE_TYPE_ORDER_CREATED,
            new DateTimeImmutable('2026-04-23T10:58:22+00:00'),
            '019db9fd-a81e-7703-8f00-a27ee9795ff9',
            $product->getId(),
            2,
            1,
        );

        $service->orderCreated($message);
        $this->entityManager->refresh($product);

        $inboxRow = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT status FROM inbox WHERE event_id = ?',
            [$message->eventId],
        );
        $outboxRows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT event_type, status FROM outbox ORDER BY created_at',
        );

        self::assertSame(3, $product->getQuantity());
        self::assertSame(2, $product->getVersion());
        self::assertSame(['status' => 'processed'], $inboxRow);
        self::assertCount(2, $outboxRows);
        self::assertSame(RabbitMQServiceInterface::MESSAGE_TYPE_ORDER_PROCESSING_STATUS, $outboxRows[0]['event_type']);
        self::assertSame(RabbitMQServiceInterface::MESSAGE_TYPE_PRODUCT_UPDATED, $outboxRows[1]['event_type']);
        self::assertSame('published', $outboxRows[0]['status']);
        self::assertSame('published', $outboxRows[1]['status']);
        self::assertCount(0, $outboxRepository->findCreatedOrdered());
        self::assertCount(1, $statusTransport->getSent());
        self::assertCount(1, $productTransport->getSent());
    }

    /**
     * @throws Exception
     * @throws ORMException
     * @throws JsonException
     */
    public function testOrderCreatedRejectsVersionMismatchWithoutChangingStock(): void
    {
        $product = $this->createProduct('019db9fd-5141-783b-804e-3f3d8ab184e7', 'Coffee Mug', 12.99, 5, 1);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $service = self::getContainer()->get(RabbitMQServiceInterface::class);

        assert($service instanceof RabbitMQServiceInterface);

        $message = new OrderCreatedMessage(
            '019db9fd-bbbb-7dcc-a5f2-327878166002',
            RabbitMQServiceInterface::MESSAGE_TYPE_ORDER_CREATED,
            new DateTimeImmutable('2026-04-23T10:58:22+00:00'),
            '019db9fd-d2e5-7dcc-a5f2-327878166002',
            $product->getId(),
            1,
            2,
        );

        $service->orderCreated($message);
        $this->entityManager->refresh($product);

        $inboxRow = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT status FROM inbox WHERE event_id = ?',
            [$message->eventId],
        );
        $statusRow = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT event FROM outbox WHERE event_type = ? LIMIT 1',
            [RabbitMQServiceInterface::MESSAGE_TYPE_ORDER_PROCESSING_STATUS],
        );
        $statusEvent = json_decode((string)($statusRow['event'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(5, $product->getQuantity());
        self::assertSame(1, $product->getVersion());
        self::assertSame(['status' => 'failed'], $inboxRow);
        self::assertSame('failed', $statusEvent['status'] ?? null);
        self::assertStringContainsString('Expected version 2, actual version 1', (string)($statusEvent['error'] ?? ''));
    }

    /** @noinspection PhpSameParameterValueInspection */
    private function createProduct(string $id, string $name, float $price, int $quantity, int $version): Product
    {
        $product = new Product();
        $product->setId($id);
        $product->setName($name);
        $product->setPrice($price);
        $product->setQuantity($quantity);
        $product->setVersion($version);

        return $product;
    }
}
