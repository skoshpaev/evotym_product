<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Product;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testSettersStoreProductFields(): void
    {
        $product = new Product();
        $product->setId('019db9fd-5141-783b-804e-3f3d8ab184e7');
        $product->setName('Coffee Mug');
        $product->setPrice(12.99);
        $product->setQuantity(5);
        $product->setVersion(1);

        self::assertSame('019db9fd-5141-783b-804e-3f3d8ab184e7', $product->getId());
        self::assertSame('Coffee Mug', $product->getName());
        self::assertSame(12.99, $product->getPrice());
        self::assertSame(5, $product->getQuantity());
        self::assertSame(1, $product->getVersion());
    }

    public function testLastOrderEventAtCanBeUpdated(): void
    {
        $product = new Product();
        $createdAt = new \DateTimeImmutable('2026-04-24T10:00:00+00:00');

        $product->setLastOrderEventAt($createdAt);

        self::assertSame($createdAt, $product->getLastOrderEventAt());
    }
}
