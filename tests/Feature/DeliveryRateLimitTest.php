<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\Delivery\ChannelRateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeliveryRateLimitTest extends TestCase
{
    public function test_redis_shares_a_rolling_limit_per_channel_across_workers(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set RUN_REDIS_INTEGRATION=1 to exercise Redis.');
        }

        config([
            'notifications.rate_limit' => 2,
            'notifications.rate_limit_prefix' => 'test:delivery:'.Str::uuid().':',
        ]);
        $firstWorker = new ChannelRateLimiter;
        $secondWorker = new ChannelRateLimiter;

        $this->assertTrue($firstWorker->acquire('sms'));
        $this->assertTrue($secondWorker->acquire('sms'));
        $this->assertFalse($firstWorker->acquire('sms'));
        $this->assertTrue($secondWorker->acquire('email'));
        $this->assertTrue($secondWorker->acquire('push'));

        usleep(1_050_000);

        $this->assertTrue($firstWorker->acquire('sms'));
    }
}
