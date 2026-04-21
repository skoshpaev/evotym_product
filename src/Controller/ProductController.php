<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateProductRequestDto;
use App\Dto\ProductResponseDto;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/products')]
final class ProductController
{
    #[Route('', name: 'product_create', methods: ['POST'])]
    public function create(
        CreateProductRequestDto $createProductRequestDto,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $product = Product::create(
            $createProductRequestDto->name,
            $createProductRequestDto->price,
            $createProductRequestDto->quantity,
        );

        $entityManager->persist($product);
        $entityManager->flush();

        return new JsonResponse(
            ProductResponseDto::fromEntity($product)->toArray(),
            Response::HTTP_CREATED,
        );
    }

    #[Route('', name: 'product_list', methods: ['GET'])]
    public function list(ProductRepository $productRepository): JsonResponse
    {
        $data = array_map(
            static fn (Product $product): array => ProductResponseDto::fromEntity($product)->toArray(),
            $productRepository->findAllOrderedByName(),
        );

        return new JsonResponse(['data' => $data]);
    }

    #[Route('/{id}', name: 'product_show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(Product $product): JsonResponse
    {
        return new JsonResponse(ProductResponseDto::fromEntity($product)->toArray());
    }
}
