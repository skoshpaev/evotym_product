<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use Evotym\SharedBundle\Dto\ProductViewDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/products/{id}', name: 'product_show', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
final class ShowProductController
{
    public function __invoke(Product $product): JsonResponse
    {
        return new JsonResponse(ProductViewDto::fromProduct($product)->toArray());
    }
}
