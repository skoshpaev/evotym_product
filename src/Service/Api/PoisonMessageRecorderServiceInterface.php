<?php

declare(strict_types=1);

namespace App\Service\Api;

interface PoisonMessageRecorderServiceInterface
{
    public function record(object $message, \Throwable $throwable, string $failureTransportName): void;
}
