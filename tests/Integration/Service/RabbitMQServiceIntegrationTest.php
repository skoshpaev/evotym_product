<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Product;
use App\Message\OrderCreatedMessage;
use App\Repository\OutboxMessageRepository;
use App\Service\RabbitMQServiceInterface;
use App\Tests\Integration\DatabaseKernelTestCase;

final class RabbitMQServiceIntegrationTest extends DatabaseKernelTestCase
{
    protected function transportNames(): array
    {
        return ['product_updated', 'order_processing_status', 'order_created', 'order_created_failed'];
    }

    public function testOrderCreatedReservesStockAndStoresInboxAndOutboxRecords(): void
    {
        $product = Product::create('Coffee Mug', 12.99, 5);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $service = static::getContainer()->get(RabbitMQServiceInterface::class);
        $outboxRepository = static::getContainer()->get(OutboxMessageRepository::class);

        \assert($service instanceof RabbitMQServiceInterface);
        \assert($outboxRepository instanceof OutboxMessageRepository);

        $message = OrderCreatedMessage::create(
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
            'SELECT event_type FROM outbox ORDER BY created_at ASC',
        );

        self::assertSame(3, $product->getQuantity());
        self::assertSame(2, $product->getVersion());
        self::assertSame(['status' => 'processed'], $inboxRow);
        self::assertCount(2, $outboxRows);
        self::assertSame('order.processing.status', $outboxRows[0]['event_type']);
        self::assertSame('product.updated', $outboxRows[1]['event_type']);
        self::assertCount(2, $outboxRepository->findCreatedOrdered());
    }

    public function testOrderCreatedRejectsVersionMismatchWithoutChangingStock(): void
    {
        $product = Product::create('Coffee Mug', 12.99, 5);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $service = static::getContainer()->get(RabbitMQServiceInterface::class);

        \assert($service instanceof RabbitMQServiceInterface);

        $message = OrderCreatedMessage::create(
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
            ['order.processing.status'],
        );
        $statusEvent = json_decode((string) ($statusRow['event'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(5, $product->getQuantity());
        self::assertSame(1, $product->getVersion());
        self::assertSame(['status' => 'failed'], $inboxRow);
        self::assertSame('failed', $statusEvent['status'] ?? null);
        self::assertStringContainsString('Expected version 2, actual version 1', (string) ($statusEvent['error'] ?? ''));
    }
}
