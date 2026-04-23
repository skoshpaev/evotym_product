<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CreateProductRequestDto;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;

final class ProductService implements ProductServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RabbitMQServiceInterface $rabbitMQService,
    ) {
    }

    public function create(CreateProductRequestDto $createProductRequestDto): Product
    {
        $product = Product::create(
            $createProductRequestDto->name,
            $createProductRequestDto->price,
            $createProductRequestDto->quantity,
        );

        $this->entityManager->persist($product);
        $eventId = $this->rabbitMQService->productUpdated($product);
        $this->entityManager->flush();
        $this->rabbitMQService->publishOutboxMessage($eventId);

        return $product;
    }
}
