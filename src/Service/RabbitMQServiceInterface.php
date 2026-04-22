<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ProductResponseDto;
use App\Message\OrderCreatedMessage;

interface RabbitMQServiceInterface
{
    public function productUpdated(ProductResponseDto $productResponseDto): void;

    public function orderCreated(OrderCreatedMessage $message): void;
}
