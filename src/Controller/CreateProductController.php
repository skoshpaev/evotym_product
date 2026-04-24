<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateProductRequestDto;
use App\Service\Api\ProductServiceInterface;
use Evotym\SharedBundle\Dto\ProductViewDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/products', name: 'product_create', methods: ['POST'])]
final class CreateProductController
{
    public function __invoke(
        CreateProductRequestDto $createProductRequestDto,
        ProductServiceInterface $productService,
    ): JsonResponse {
        $product = $productService->create($createProductRequestDto);

        return new JsonResponse(
            ProductViewDto::fromProduct($product)->toArray(),
            Response::HTTP_CREATED,
        );
    }
}
