<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Evotym\SharedBundle\Entity\AbstractProduct;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
final class Product extends AbstractProduct
{
    public const INITIAL_VERSION = 1;

    #[ORM\Column(type: Types::INTEGER)]
    private int $version = self::INITIAL_VERSION;

    #[ORM\Column(name: 'last_order_event_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastOrderEventAt = null;

    private function __construct(string $id, string $name, float $price, int $quantity)
    {
        $this->initializeProduct($id, $name, $price, $quantity);
    }

    public static function create(string $name, float $price, int $quantity): self
    {
        return new self(Uuid::v7()->toRfc4122(), $name, $price, $quantity);
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function syncQuantity(int $quantity): void
    {
        $normalizedQuantity = self::normalizeQuantity($quantity);

        if ($normalizedQuantity === $this->quantity) {
            return;
        }

        $this->quantity = $normalizedQuantity;
        ++$this->version;
    }

    public function reserveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Reserved quantity must be greater than zero.');
        }

        if ($quantity > $this->quantity) {
            throw new \InvalidArgumentException('Ordered quantity exceeds available stock.');
        }

        $this->decreaseQuantity($quantity);
        ++$this->version;
    }

    public function getLastOrderEventAt(): ?DateTimeImmutable
    {
        return $this->lastOrderEventAt;
    }

    public function markOrderEventProcessed(DateTimeImmutable $createdAt): void
    {
        $this->lastOrderEventAt = $createdAt;
    }
}
