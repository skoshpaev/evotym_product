<?php

declare(strict_types=1);

namespace App\Http\Resolver;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class ProductValueResolver implements ValueResolverInterface
{
    public function __construct(private readonly ProductRepository $productRepository)
    {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== Product::class) {
            return [];
        }

        $id = $request->attributes->get('id');

        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('Product not found.');
        }

        $product = $this->productRepository->find($id);

        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        yield $product;
    }
}
