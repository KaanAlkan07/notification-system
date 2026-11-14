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

        $this->assertTrue($firstWorker->acquire('sms', 'first'));
        $this->assertTrue($secondWorker->acquire('sms', 'second'));
        $this->assertFalse($firstWorker->acquire('sms', 'third'));
        $this->assertTrue($secondWorker->acquire('email', 'first'));
        $this->assertTrue($secondWorker->acquire('push', 'first'));

        usleep(1_050_000);

        $this->assertFalse($firstWorker->acquire('sms', 'third'), 'In-flight requests must still count after one second.');
        $secondWorker->complete('sms', 'second');
        $this->assertFalse($firstWorker->acquire('sms', 'third'), 'Completed requests must still count for one second.');

        usleep(1_050_000);

        $this->assertTrue($firstWorker->acquire('sms', 'third'));
        $this->assertFalse($secondWorker->acquire('sms', 'fourth'), 'The first request is still in flight.');
    }
}
