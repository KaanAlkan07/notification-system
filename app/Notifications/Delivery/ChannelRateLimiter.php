<?php

declare(strict_types=1);

namespace App\Notifications\Delivery;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;

class ChannelRateLimiter
{
    private const ACQUIRE = <<<'LUA'
        local key = KEYS[1]
        local time = redis.call('TIME')
        local now = tonumber(time[1]) * 1000000 + tonumber(time[2])
        redis.call('ZREMRANGEBYSCORE', key, '-inf', now - 1000000)

        if redis.call('ZCARD', key) >= tonumber(ARGV[1]) then
            return 0
        end

        redis.call('ZADD', key, now, ARGV[2])
        redis.call('PEXPIRE', key, 2000)

        return 1
        LUA;

    public function acquire(string $channel): bool
    {
        $prefix = (string) config('notifications.rate_limit_prefix', 'notifications:delivery:rate:');
        $limit = max(1, min(100, (int) config('notifications.rate_limit', 100)));
        $result = Redis::connection()->eval(self::ACQUIRE, 1, $prefix.$channel, $limit, (string) Str::uuid());

        if ($result === 0 || $result === 1) {
            return $result === 1;
        }

        throw new RuntimeException('Unexpected Redis admission response.');
    }
}
