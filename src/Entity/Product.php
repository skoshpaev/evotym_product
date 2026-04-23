<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
final class Product
{
    public const NAME_MAX_LENGTH = 255;
    public const INITIAL_VERSION = 1;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36, unique: true)]
    private string $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    #[ORM\Column(type: Types::INTEGER)]
    private int $version = self::INITIAL_VERSION;

    #[ORM\Column(name: 'last_order_event_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastOrderEventAt = null;

    private function __construct(string $id, string $name, float $price, int $quantity)
    {
        $this->id = $id;
        $this->name = $name;
        $this->price = self::normalizePrice($price);
        $this->quantity = self::normalizeQuantity($quantity);
    }

    public static function create(string $name, float $price, int $quantity): self
    {
        return new self(Uuid::v7()->toRfc4122(), $name, $price, $quantity);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrice(): float
    {
        return (float) $this->price;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
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

        $this->quantity -= $quantity;
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

    private static function normalizePrice(float $price): string
    {
        return number_format($price, 2, '.', '');
    }

    private static function normalizeQuantity(int $quantity): int
    {
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Quantity must be greater than or equal to zero.');
        }

        return $quantity;
    }
}
