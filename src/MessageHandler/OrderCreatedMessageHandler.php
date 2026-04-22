<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\OrderCreatedMessage;
use App\Service\RabbitMQServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'order_created')]
final class OrderCreatedMessageHandler
{
    public function __construct(
        private readonly RabbitMQServiceInterface $rabbitMQService,
    ) {
    }

    public function __invoke(OrderCreatedMessage $message): void
    {
        $this->rabbitMQService->orderCreated($message);
    }
}
