<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationTrendsTest extends TestCase
{
    use RefreshDatabase;

    public function test_outcomes_are_grouped_by_channel_and_time_with_empty_buckets(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 14)->setTime(10, 35));
        $this->notification('sms', 'accepted', '2026-11-14 09:00:00');
        $this->notification('sms', 'failed', '2026-11-14 09:59:59');
        $this->notification('sms', 'uncertain', '2026-11-14 09:59:59');
        $this->notification('email', 'accepted', '2026-11-14 09:11:00');
        $this->notification('push', 'failed', '2026-11-14 10:01:00');
        $this->notification('push', 'accepted', '2026-11-13 10:59:59');
        $this->notification('sms', 'pending', null);
        $this->notification('push', 'cancelled', '2026-11-14 10:03:00');

        $bucket = $this->getJson('/api/v1/notification-trends?range=24h')->assertOk()
            ->assertJsonPath('bucket_seconds', 3600)
            ->assertJsonCount(24, 'buckets')
            ->assertJsonPath('buckets.22.start', '2026-11-14T09:00:00Z')
            ->assertJsonPath('buckets.22.channels.sms', ['completed' => 3, 'accepted' => 1])
            ->assertJsonPath('buckets.22.channels.email', ['completed' => 1, 'accepted' => 1])
            ->assertJsonPath('buckets.22.channels.push', ['completed' => 0, 'accepted' => 0])
            ->assertJsonPath('buckets.23.channels.push', ['completed' => 1, 'accepted' => 0]);

        $this->getJson('/api/v1/notification-trends?range=7d')->assertOk()
            ->assertJsonPath('bucket_seconds', 86400)
            ->assertJsonCount(7, 'buckets')
            ->assertJsonPath('buckets.5.channels.push', ['completed' => 1, 'accepted' => 1])
            ->assertJsonPath('buckets.6.channels.sms', $bucket->json('buckets.22.channels.sms'));
    }

    public function test_trends_use_api_authentication_and_validate_the_range(): void
    {
        config(['notifications.api_token' => 'audit-token']);
        $this->getJson('/api/v1/notification-trends')->assertUnauthorized();
        $this->getJson('/api/v1/notification-trends?range=365d', ['Authorization' => 'Bearer audit-token'])
            ->assertUnprocessable()->assertJsonValidationErrors('range');
    }

    private function notification(string $channel, string $status, ?string $finishedAt): void
    {
        DB::table('notifications')->insert([
            'id' => Str::uuid()->toString(),
            'correlation_id' => 'trend-test',
            'channel' => $channel,
            'recipient' => 'synthetic',
            'content' => 'Synthetic report row',
            'priority' => 'normal',
            'status' => $status,
            'scheduled_at' => '2026-11-13 09:00:00',
            'finished_at' => $finishedAt,
        ]);
    }
}
