<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Message\OrderCreatedMessage;

interface RabbitMQServiceInterface
{
    public function productUpdated(Product $product): string;

    public function orderCreated(OrderCreatedMessage $message): void;

    public function publishOutboxMessage(string $eventId): bool;

    public function publishPendingOutbox(): int;
}
