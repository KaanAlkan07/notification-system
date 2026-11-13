<?php

declare(strict_types=1);

namespace App\Notifications\Delivery;

final readonly class ProviderResult
{
    private function __construct(
        public string $status,
        public ?string $messageId = null,
        public ?string $failureCode = null,
        public ?string $retryAfter = null,
    ) {}

    public static function accepted(string $messageId): self
    {
        return new self('accepted', messageId: $messageId);
    }

    public static function retryable(string $code, ?string $retryAfter): self
    {
        return new self('retrying', failureCode: $code, retryAfter: $retryAfter);
    }

    public static function failed(string $code): self
    {
        return new self('failed', failureCode: $code);
    }

    public static function uncertain(string $code): self
    {
        return new self('uncertain', failureCode: $code);
    }
}
