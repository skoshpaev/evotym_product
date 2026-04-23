<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\CreateProductRequestDto;
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

    public function testCreatePersistsProductAndStoresCreatedOutboxEvent(): void
    {
        $service = static::getContainer()->get(ProductServiceInterface::class);
        \assert($service instanceof ProductServiceInterface);

        $product = $service->create(new CreateProductRequestDto('Coffee Mug', 12.99, 5));

        $productRepository = static::getContainer()->get(ProductRepository::class);
        $outboxRepository = static::getContainer()->get(OutboxMessageRepository::class);

        \assert($productRepository instanceof ProductRepository);
        \assert($outboxRepository instanceof OutboxMessageRepository);

        $storedProduct = $productRepository->find($product->getId());
        $outboxMessages = $outboxRepository->findCreatedOrdered();

        self::assertNotNull($storedProduct);
        self::assertSame(1, $storedProduct->getVersion());
        self::assertCount(1, $outboxMessages);
        self::assertSame(ProductUpdatedMessage::TYPE, $outboxMessages[0]->getEventType());
        self::assertSame(1, $outboxMessages[0]->getEvent()['version']);
    }

    public function testPublishPendingOutboxDispatchesEventsToInMemoryTransport(): void
    {
        $service = static::getContainer()->get(ProductServiceInterface::class);
        $rabbitMqService = static::getContainer()->get(RabbitMQServiceInterface::class);
        $transport = $this->getTransport('product_updated');

        \assert($service instanceof ProductServiceInterface);
        \assert($rabbitMqService instanceof RabbitMQServiceInterface);

        $service->create(new CreateProductRequestDto('Tea Cup', 7.50, 3));

        self::assertCount(0, $transport->getSent());
        self::assertSame(1, $rabbitMqService->publishPendingOutbox());
        self::assertCount(1, $transport->getSent());
        self::assertInstanceOf(ProductUpdatedMessage::class, $transport->getSent()[0]->getMessage());
    }
}
