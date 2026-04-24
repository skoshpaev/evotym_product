<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CreateProductRequestDto;
use App\Entity\Product;
use App\Service\Api\ProductServiceInterface;
use App\Service\Api\RabbitMQServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class ProductService implements ProductServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RabbitMQServiceInterface $rabbitMQService,
    ) {
    }

    public function create(CreateProductRequestDto $createProductRequestDto): Product
    {
        $product = new Product();
        $product->setId(Uuid::v7()->toRfc4122());
        $product->setName($createProductRequestDto->name);
        $product->setPrice($createProductRequestDto->price);
        $product->setQuantity($createProductRequestDto->quantity);
        $product->setVersion(self::INITIAL_VERSION);

        $this->entityManager->persist($product);

        $eventId = $this->rabbitMQService->productUpdated($product);

        $this->entityManager->flush();
        $this->rabbitMQService->publishOutboxMessage($eventId);

        return $product;
    }
}
