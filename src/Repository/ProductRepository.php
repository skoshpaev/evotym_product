<?php
/** @noinspection PhpMultipleClassDeclarationsInspection */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
final class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return list<Product>
     */
    public function findAllOrderedByName(): array
    {
        /** @var list<Product> $products */
        $products = $this->createQueryBuilder('product')
            ->orderBy('product.name', 'ASC')
            ->addOrderBy('product.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $products;
    }
}
