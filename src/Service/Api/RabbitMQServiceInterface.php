<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Entity\OutboxMessage;
use App\Entity\Product;
use App\Message\OrderCreatedMessage;

interface RabbitMQServiceInterface
{
    public const MESSAGE_TYPE_PRODUCT_UPDATED = 'product.updated';
    public const MESSAGE_TYPE_ORDER_CREATED = 'order.created';
    public const MESSAGE_TYPE_ORDER_PROCESSING_STATUS = 'order.processing.status';

    public const ORDER_STATUS_PROCESSED = 'processed';
    public const ORDER_STATUS_FAILED = 'failed';

    public const INBOX_STATUS_PROCESSED = 'processed';
    public const INBOX_STATUS_FAILED = 'failed';
    public const INBOX_STATUS_IGNORED = 'ignored';

    public const OUTBOX_STATUS_CREATED = 'created';
    public const OUTBOX_STATUS_PUBLISHED = 'published';

    public function productUpdated(Product $product): string;

    public function orderCreated(OrderCreatedMessage $message): void;

    public function publishOutboxMessage(string $eventId): bool;

    /**
     * @param list<OutboxMessage> $createdProducts
     */
    public function publishPendingOutbox(array $createdProducts): int;
}
