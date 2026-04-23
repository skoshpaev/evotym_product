<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\CreateProductRequestDto;
use App\Entity\Product;
use App\Service\ProductService;
use App\Service\RabbitMQServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProductServiceTest extends TestCase
{
    public function testCreatePersistsProductQueuesOutboxEventAndFlushes(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $rabbitMqService = $this->createMock(RabbitMQServiceInterface::class);
        $service = new ProductService($entityManager, $rabbitMqService);
        $dto = new CreateProductRequestDto('Coffee Mug', 12.99, 5);

        $persistedProduct = null;

        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $entity) use (&$persistedProduct): bool {
                $persistedProduct = $entity;

                return $entity instanceof Product;
            }));

        $rabbitMqService
            ->expects(self::once())
            ->method('productUpdated')
            ->with(self::callback(static function (Product $product) use (&$persistedProduct): bool {
                return $persistedProduct === $product
                    && $product->getName() === 'Coffee Mug'
                    && $product->getPrice() === 12.99
                    && $product->getQuantity() === 5;
            }));

        $entityManager->expects(self::once())->method('flush');

        $product = $service->create($dto);

        self::assertInstanceOf(Product::class, $product);
        self::assertSame('Coffee Mug', $product->getName());
        self::assertSame(12.99, $product->getPrice());
        self::assertSame(5, $product->getQuantity());
    }
}
