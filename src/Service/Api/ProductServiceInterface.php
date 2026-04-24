<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Dto\CreateProductRequestDto;
use App\Entity\Product;

interface ProductServiceInterface
{
    public const INITIAL_VERSION = 1;

    public function create(CreateProductRequestDto $createProductRequestDto): Product;
}
