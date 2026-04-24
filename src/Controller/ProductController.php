<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateProductRequestDto;
use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\ProductServiceInterface;
use Evotym\SharedBundle\Dto\ProductViewDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/products')]
final class ProductController
{
    #[Route('', name: 'product_create', methods: ['POST'])]
    public function create(
        CreateProductRequestDto $createProductRequestDto,
        ProductServiceInterface $productService,
    ): JsonResponse {
        $product = $productService->create($createProductRequestDto);

        return new JsonResponse(
            ProductViewDto::fromProduct($product)->toArray(),
            Response::HTTP_CREATED,
        );
    }

    #[Route('', name: 'product_list', methods: ['GET'])]
    public function list(ProductRepository $productRepository): JsonResponse
    {
        $data = array_map(
            static fn (Product $product): array => ProductViewDto::fromProduct($product)->toArray(),
            $productRepository->findAllOrderedByName(),
        );

        return new JsonResponse(['data' => $data]);
    }

    #[Route('/{id}', name: 'product_show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(Product $product): JsonResponse
    {
        return new JsonResponse(ProductViewDto::fromProduct($product)->toArray());
    }
}
