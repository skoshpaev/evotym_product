<?php

declare(strict_types=1);

namespace App\Dto;

use Evotym\SharedBundle\Entity\AbstractProduct;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateProductRequestDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: AbstractProduct::NAME_MAX_LENGTH)]
        public readonly string $name,
        #[Assert\GreaterThan(0)]
        public readonly float $price,
        #[Assert\GreaterThanOrEqual(0)]
        public readonly int $quantity,
    ) {
    }
}
