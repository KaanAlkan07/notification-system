<?php

declare(strict_types=1);

namespace App\Notifications\Delivery;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class RetryPolicy
{
    private const MAX_RETRY_AFTER = 300;

    public function delay(int $attempt, ?string $retryAfter, DateTimeInterface $now): ?int
    {
        $providerDelay = $this->providerDelay(trim($retryAfter ?? ''), $now);
        if ($providerDelay > self::MAX_RETRY_AFTER) {
            return null;
        }

        $base = min(60, 2 ** min(6, max(1, $attempt)));
        $backoff = $base + random_int(0, intdiv($base, 2));

        return max($providerDelay, $backoff);
    }

    private function providerDelay(string $retryAfter, DateTimeInterface $now): int
    {
        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!D, d M Y H:i:s \G\M\T',
            $retryAfter,
            new DateTimeZone('UTC'),
        );

        if ($date === false || $date->format('D, d M Y H:i:s \G\M\T') !== $retryAfter) {
            return 0;
        }

        return $date->getTimestamp() - $now->getTimestamp();
    }
}
