<?php

declare(strict_types=1);

namespace App\Notifications\Delivery;

use Illuminate\Support\Facades\Redis;
use RuntimeException;

class ChannelRateLimiter
{
    // Outlasts the worker timeout and the configured HTTP timeout.
    private const LEASE_MICROSECONDS = 60_000_000;

    private const ACQUIRE = <<<'LUA'
        local key = KEYS[1]
        local time = redis.call('TIME')
        local now = tonumber(time[1]) * 1000000 + tonumber(time[2])
        local lease = tonumber(ARGV[3])
        redis.call('ZREMRANGEBYSCORE', key, '-inf', now)

        if redis.call('ZCARD', key) >= tonumber(ARGV[1]) then
            return 0
        end

        if redis.call('ZADD', key, 'NX', now + lease, ARGV[2]) == 0 then
            return 0
        end
        redis.call('PEXPIRE', key, math.ceil(lease / 1000))

        return 1
        LUA;

    private const COMPLETE = <<<'LUA'
        local time = redis.call('TIME')
        local now = tonumber(time[1]) * 1000000 + tonumber(time[2])
        redis.call('ZADD', KEYS[1], 'XX', 'LT', now + 1000000, ARGV[1])
        return 1
        LUA;

    public function acquire(string $channel, string $reservation): bool
    {
        $limit = max(1, min(100, (int) config('notifications.rate_limit', 100)));
        $result = Redis::connection()->eval(
            self::ACQUIRE, 1, $this->key($channel), $limit, $reservation, self::LEASE_MICROSECONDS,
        );

        if ($result === 0 || $result === 1) {
            return $result === 1;
        }

        throw new RuntimeException('Unexpected Redis admission response.');
    }

    public function complete(string $channel, string $reservation): void
    {
        Redis::connection()->eval(self::COMPLETE, 1, $this->key($channel), $reservation);
    }

    private function key(string $channel): string
    {
        $prefix = (string) config('notifications.rate_limit_prefix', 'notifications:delivery:rate:');

        return $prefix.$channel;
    }
}
