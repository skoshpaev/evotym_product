<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Message\OrderCreatedMessage;

interface RabbitMQServiceInterface
{
    public function productUpdated(Product $product): void;

    public function orderCreated(OrderCreatedMessage $message): void;

    public function publishPendingOutbox(): int;
}
