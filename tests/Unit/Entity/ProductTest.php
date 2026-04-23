<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Product;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testReserveQuantityDecreasesStockAndIncrementsVersion(): void
    {
        $product = Product::create('Coffee Mug', 12.99, 5);

        self::assertSame(1, $product->getVersion());
        self::assertSame(5, $product->getQuantity());

        $product->reserveQuantity(2);

        self::assertSame(3, $product->getQuantity());
        self::assertSame(2, $product->getVersion());
    }

    public function testSyncQuantityKeepsVersionWhenValueDoesNotChange(): void
    {
        $product = Product::create('Coffee Mug', 12.99, 5);

        $product->syncQuantity(5);

        self::assertSame(5, $product->getQuantity());
        self::assertSame(1, $product->getVersion());
    }

    public function testReserveQuantityRejectsQuantityAboveAvailableStock(): void
    {
        $product = Product::create('Coffee Mug', 12.99, 5);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ordered quantity exceeds available stock.');

        $product->reserveQuantity(6);
    }
}
