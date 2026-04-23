<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\CreateProductRequestDto;
use App\Entity\Product;
use App\Message\ProductUpdatedMessage;
use App\Repository\OutboxMessageRepository;
use App\Repository\ProductRepository;
use App\Service\ProductServiceInterface;
use App\Service\RabbitMQServiceInterface;
use App\Tests\Integration\DatabaseKernelTestCase;

final class ProductServiceIntegrationTest extends DatabaseKernelTestCase
{
    protected function transportNames(): array
    {
        return ['product_updated', 'order_processing_status'];
    }

    public function testCreatePersistsProductDispatchesEventImmediatelyAndMarksOutboxAsPublished(): void
    {
        $service = static::getContainer()->get(ProductServiceInterface::class);
        $transport = $this->getTransport('product_updated');
        \assert($service instanceof ProductServiceInterface);

        $product = $service->create(new CreateProductRequestDto('Coffee Mug', 12.99, 5));

        $productRepository = static::getContainer()->get(ProductRepository::class);
        $outboxRepository = static::getContainer()->get(OutboxMessageRepository::class);

        \assert($productRepository instanceof ProductRepository);
        \assert($outboxRepository instanceof OutboxMessageRepository);

        $storedProduct = $productRepository->find($product->getId());
        $outboxMessages = $outboxRepository->findAll();

        self::assertNotNull($storedProduct);
        self::assertSame(1, $storedProduct->getVersion());
        self::assertCount(1, $outboxMessages);
        self::assertSame(ProductUpdatedMessage::TYPE, $outboxMessages[0]->getEventType());
        self::assertSame(1, $outboxMessages[0]->getEvent()['version']);
        self::assertSame('published', $outboxMessages[0]->getStatus());
        self::assertCount(1, $transport->getSent());
        self::assertCount(0, $outboxRepository->findCreatedOrdered());
    }

    public function testPublishPendingOutboxDispatchesQueuedEventsWhenTheyWereNotPublishedImmediately(): void
    {
        $rabbitMqService = static::getContainer()->get(RabbitMQServiceInterface::class);
        $outboxRepository = static::getContainer()->get(OutboxMessageRepository::class);
        $transport = $this->getTransport('product_updated');

        \assert($rabbitMqService instanceof RabbitMQServiceInterface);
        \assert($outboxRepository instanceof OutboxMessageRepository);

        $product = Product::create('Tea Cup', 7.50, 3);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $rabbitMqService->productUpdated($product);
        $this->entityManager->flush();

        self::assertCount(0, $transport->getSent());
        self::assertCount(1, $outboxRepository->findCreatedOrdered());
        self::assertSame(1, $rabbitMqService->publishPendingOutbox());
        self::assertCount(1, $transport->getSent());
        self::assertCount(0, $outboxRepository->findCreatedOrdered());
        self::assertInstanceOf(ProductUpdatedMessage::class, $transport->getSent()[0]->getMessage());
    }
}
