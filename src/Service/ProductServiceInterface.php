<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CreateProductRequestDto;
use App\Entity\Product;

interface ProductServiceInterface
{
    public function create(CreateProductRequestDto $createProductRequestDto): Product;
}
