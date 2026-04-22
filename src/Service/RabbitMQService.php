<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ProductResponseDto;
use App\Message\OrderCreatedMessage;
use App\Message\ProductUpdatedMessage;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

final class RabbitMQService implements RabbitMQServiceInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ProductRepository $productRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function productUpdated(ProductResponseDto $productResponseDto): void
    {
        $this->messageBus->dispatch(
            new ProductUpdatedMessage(
                $productResponseDto->id,
                $productResponseDto->name,
                $productResponseDto->price,
                $productResponseDto->quantity,
            ),
        );
    }

    public function orderCreated(OrderCreatedMessage $message): void
    {
        $product = $this->productRepository->find($message->productId);

        if ($product === null) {
            throw new UnrecoverableMessageHandlingException(
                sprintf('Product "%s" was not found while applying order.created event.', $message->productId),
            );
        }

        $product->syncQuantity($message->quantity);
        $this->entityManager->flush();
    }
}
