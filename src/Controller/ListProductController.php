<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Evotym\SharedBundle\Dto\ProductViewDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/products', name: 'product_list', methods: ['GET'])]
final class ListProductController
{
    public function __invoke(ProductRepository $productRepository): JsonResponse
    {
        $data = array_map(
            static fn (Product $product): array => ProductViewDto::fromProduct($product)->toArray(),
            $productRepository->findAllOrderedByName(),
        );

        return new JsonResponse(['data' => $data]);
    }
}
