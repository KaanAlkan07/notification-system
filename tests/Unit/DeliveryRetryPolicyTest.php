<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notifications\Delivery\RetryPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DeliveryRetryPolicyTest extends TestCase
{
    public function test_backoff_increases_exponentially_with_bounded_jitter(): void
    {
        $policy = new RetryPolicy;
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $initial = $policy->delay(1, null, $now);
        $later = $policy->delay(4, null, $now);

        $this->assertGreaterThanOrEqual(2, $initial);
        $this->assertLessThanOrEqual(3, $initial);
        $this->assertGreaterThanOrEqual(16, $later);
        $this->assertLessThanOrEqual(24, $later);
    }

    public function test_provider_delay_is_respected_in_both_http_formats(): void
    {
        $policy = new RetryPolicy;
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $this->assertSame(17, $policy->delay(1, '17', $now));
        $this->assertSame(17, $policy->delay(1, 'Thu, 01 Jan 2026 00:00:17 GMT', $now));
        $this->assertSame(300, $policy->delay(1, '300', $now));
        $this->assertNull($policy->delay(1, '301', $now));
        $this->assertNull($policy->delay(1, '999999999999999999999999999999', $now));
    }
}
