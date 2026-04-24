<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\CreateProductRequestDto;
use App\Entity\Product;
use App\Repository\OutboxMessageRepository;
use App\Repository\ProductRepository;
use App\Service\Api\ProductServiceInterface;
use App\Service\Api\RabbitMQServiceInterface;
use App\Tests\Integration\DatabaseKernelTestCase;

final class ProductServiceIntegrationTest extends DatabaseKernelTestCase
{
    protected function transportNames(): array
    {
        return ['product_updated', 'order_processing_status'];
    }

    public function testCreatePersistsProductDispatchesEventImmediatelyAndMarksOutboxAsPublished(): void
    {
        $service = ProductServiceIntegrationTest::getContainer()->get(ProductServiceInterface::class);
        $transport = $this->getTransport('product_updated');
        assert($service instanceof ProductServiceInterface);

        $product = $service->create(new CreateProductRequestDto('Coffee Mug', 12.99, 5));

        $productRepository = ProductServiceIntegrationTest::getContainer()->get(ProductRepository::class);
        $outboxRepository = ProductServiceIntegrationTest::getContainer()->get(OutboxMessageRepository::class);

        assert($productRepository instanceof ProductRepository);
        assert($outboxRepository instanceof OutboxMessageRepository);

        $storedProduct = $productRepository->find($product->getId());
        $outboxMessages = $outboxRepository->findAll();

        self::assertNotNull($storedProduct);
        self::assertSame(1, $storedProduct->getVersion());
        self::assertCount(1, $outboxMessages);
        self::assertSame(RabbitMQServiceInterface::MESSAGE_TYPE_PRODUCT_UPDATED, $outboxMessages[0]->getEventType());
        self::assertSame(1, $outboxMessages[0]->getEvent()['version']);
        self::assertSame('published', $outboxMessages[0]->getStatus());
        self::assertCount(1, $transport->getSent());
        self::assertCount(0, $outboxRepository->findCreatedOrdered());
    }

    public function testPublishPendingOutboxDispatchesQueuedEventsWhenTheyWereNotPublishedImmediately(): void
    {
        $rabbitMqService = ProductServiceIntegrationTest::getContainer()->get(RabbitMQServiceInterface::class);
        $outboxRepository = ProductServiceIntegrationTest::getContainer()->get(OutboxMessageRepository::class);
        $transport = $this->getTransport('product_updated');

        assert($rabbitMqService instanceof RabbitMQServiceInterface);
        assert($outboxRepository instanceof OutboxMessageRepository);

        $product = new Product();
        $product->setId('019db9fd-5141-783b-804e-3f3d8ab184e7');
        $product->setName('Tea Cup');
        $product->setPrice(7.50);
        $product->setQuantity(3);
        $product->setVersion(1);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $rabbitMqService->productUpdated($product);
        $this->entityManager->flush();

        self::assertCount(0, $transport->getSent());
        self::assertCount(1, $outboxRepository->findCreatedOrdered());
        self::assertSame(1, $rabbitMqService->publishPendingOutbox($outboxRepository->findCreatedOrdered()));
        self::assertCount(1, $transport->getSent());
        self::assertCount(0, $outboxRepository->findCreatedOrdered());
    }
}
