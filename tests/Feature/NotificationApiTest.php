<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/notifications';

    private const BATCHES = '/api/v1/notification-batches';

    public function test_it_creates_a_notification_with_a_durable_priority_job(): void
    {
        $response = $this->postJson(self::BASE, $this->sms(['priority' => 'high']), [
            'Idempotency-Key' => 'checkout-17',
            'X-Correlation-ID' => 'test-trace',
        ])->assertAccepted();

        $id = $response->json('data.id');
        $response->assertHeader('Location', self::BASE.'/'.$id)
            ->assertHeader('X-Correlation-ID', 'test-trace')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.recipient')
            ->assertJsonMissingPath('data.content');

        $this->assertDatabaseHas('jobs', ['queue' => 'high', 'attempts' => 0]);
        $this->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_idempotent_replays_and_payload_conflicts(): void
    {
        $headers = ['Idempotency-Key' => 'payment-02'];
        $first = $this->postJson(self::BASE, $this->sms(), $headers)->assertAccepted();
        $this->postJson(self::BASE, $this->sms(['priority' => 'normal']), $headers)
            ->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson(self::BASE, $this->sms(['content' => 'Changed']), $headers)
            ->assertConflict();
        $this->postJson(self::BATCHES, ['notifications' => [$this->sms()]], $headers)
            ->assertAccepted();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_batch_accepts_1000_and_paginates_status_with_counts(): void
    {
        $batch = array_fill(0, 1000, $this->sms());
        $response = $this->postJson(self::BATCHES, ['notifications' => $batch])
            ->assertAccepted()->assertJsonPath('data.count', 1000);
        $path = self::BATCHES.'/'.$response->json('data.id');
        $response->assertHeader('Location', $path);

        $first = $this->getJson($path.'?per_page=2')
            ->assertOk()->assertJsonCount(2, 'notifications.data')
            ->assertJsonPath('data.status_counts.pending', 1000);
        $cursor = $first->json('notifications.next_cursor');
        $this->assertIsString($cursor);
        $second = $this->getJson($path.'?per_page=2&cursor='.urlencode($cursor))->assertOk();
        $this->assertNotSame($first->json('notifications.data.0.id'), $second->json('notifications.data.0.id'));
        $this->assertDatabaseCount('jobs', 1000);

        $batch[] = $this->sms();
        $this->postJson(self::BATCHES, ['notifications' => $batch])
            ->assertUnprocessable()->assertJsonValidationErrors('notifications');
        $this->assertDatabaseCount('jobs', 1000);
    }

    public function test_batch_and_idempotency_roll_back_when_queue_insertion_fails(): void
    {
        Queue::shouldReceive('connection')->once()->with('database')->andThrow(new RuntimeException('queue unavailable'));
        $this->postJson(self::BATCHES, ['notifications' => [$this->sms()]], [
            'Idempotency-Key' => 'atomic',
        ])->assertServerError();
        $this->assertDatabaseCount('notification_batches', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function test_schedules_filters_and_cancels_pending_notifications(): void
    {
        $scheduled = now()->addMinutes(10)->startOfSecond();
        $first = $this->postJson(self::BASE, $this->sms(['scheduled_at' => $scheduled->toIso8601String()]))
            ->assertAccepted();
        $path = self::BASE.'/'.$first->json('data.id');
        $this->assertDatabaseHas('jobs', ['available_at' => $scheduled->timestamp]);
        $this->postJson($path.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($path.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson(self::BASE, $this->sms())->assertAccepted();
        $this->getJson(self::BASE.'?status=cancelled&channel=sms')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'?channel=push')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'?from='.urlencode($scheduled->toIso8601String()))->assertOk()->assertJsonCount(0, 'data');
        DB::table('notifications')->where('id', $first->json('data.id'))->update(['status' => 'processing']);
        $this->postJson($path.'/cancel')->assertConflict();
    }

    public function test_validation_rejects_invalid_channel_content_and_schedules(): void
    {
        $this->postJson(self::BASE, $this->sms([
            'recipient' => '123',
            'content' => str_repeat('á', 161),
            'scheduled_at' => 'tomorrow',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['recipient', 'content', 'scheduled_at']);
        $this->postJson(self::BASE, $this->sms(['channel' => ['sms']]))
            ->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->postJson(self::BASE, $this->sms(['scheduled_at' => '2026-02-31T00:00:00Z']))
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $beyondSupportedRange = '9999-12-31T23:59:59.999999Z';
        $this->postJson(self::BASE, $this->sms(['scheduled_at' => $beyondSupportedRange]))
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->getJson(self::BASE.'?from='.urlencode($beyondSupportedRange))
            ->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->postJson(self::BASE.'?'.http_build_query($this->sms()))
            ->assertUnprocessable()->assertJsonValidationErrors('recipient');
        $this->postJson(self::BATCHES, ['notifications' => (object) [$this->sms()]])
            ->assertUnprocessable()->assertJsonValidationErrors('notifications');
        $this->postJson(self::BASE, $this->sms(), ['Idempotency-Key' => 'with space'])
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
        $this->getJson(self::BASE.'?cursor=broken')->assertUnprocessable()->assertJsonValidationErrors('cursor');
        foreach (['null', '[]', '{"id":"01900000-0000-7000-8000-000000000000"}'] as $invalidCursor) {
            $encoded = rtrim(strtr(base64_encode($invalidCursor), '+/', '-_'), '=');
            $this->getJson(self::BASE.'?cursor='.$encoded)->assertUnprocessable()->assertJsonValidationErrors('cursor');
        }
        $this->postJson(self::BATCHES, ['notifications' => [$this->sms(), 'wrong-shape']])
            ->assertUnprocessable()->assertJsonValidationErrors('notifications.1');
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_manual_retry_requeues_a_failure_once_and_keeps_the_notification_id(): void
    {
        $id = $this->postJson(self::BASE, $this->sms(['priority' => 'high']))->assertAccepted()->json('data.id');
        DB::table('jobs')->delete();
        DB::table('notifications')->where('id', $id)->update([
            'status' => 'failed',
            'attempts' => 5,
            'failure_code' => 'http_503',
            'finished_at' => now(),
        ]);
        $path = self::BASE.'/'.$id.'/retry';

        $this->postJson($path, ['retry_generation' => 0])->assertAccepted()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.attempts', 0)
            ->assertJsonPath('data.failure_code', null)
            ->assertJsonPath('data.finished_at', null)
            ->assertJsonPath('data.retry_generation', 1);

        $this->postJson($path, ['retry_generation' => 0])->assertConflict();
        DB::table('notifications')->where('id', $id)->update(['status' => 'failed']);
        $this->postJson($path, ['retry_generation' => 0])->assertConflict();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'high']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_manual_retry_is_rejected_for_uncertain_deliveries(): void
    {
        $id = $this->postJson(self::BASE, $this->sms())->assertAccepted()->json('data.id');
        DB::table('notifications')->where('id', $id)->update(['status' => 'uncertain']);

        $this->postJson(self::BASE.'/'.$id.'/retry', ['retry_generation' => 0])->assertConflict();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('notifications', ['id' => $id, 'status' => 'uncertain']);
    }

    public function test_bearer_token_protects_api_and_metrics_when_configured(): void
    {
        config(['notifications.api_token' => 'test-token']);
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->get('/metrics')->assertUnauthorized();
        $this->getJson(self::BASE, ['Authorization' => 'Bearer test-token'])->assertOk();
        $this->get('/metrics', ['Authorization' => 'Bearer test-token'])->assertOk()
            ->assertSee('notifications_queue_depth{priority="high"} 0', false);
        Redis::shouldReceive('connection')->andThrow(new RuntimeException('redis unavailable'));
        $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.redis', 'down');
    }

    public function test_metrics_report_queue_depth_and_recent_outcomes_and_latency(): void
    {
        $this->freezeSecond();
        $accepted = $this->postJson(self::BASE, $this->sms())->assertAccepted()->json('data.id');
        $failed = $this->postJson(self::BASE, $this->sms())->assertAccepted()->json('data.id');
        $old = $this->postJson(self::BASE, $this->sms())->assertAccepted()->json('data.id');
        DB::table('jobs')->delete();
        DB::table('notifications')->where('id', $accepted)->update([
            'status' => 'accepted', 'scheduled_at' => now()->subSeconds(7), 'finished_at' => now(),
        ]);
        DB::table('notifications')->where('id', $failed)->update(['status' => 'failed', 'finished_at' => now()]);
        DB::table('notifications')->where('id', $old)->update([
            'status' => 'failed', 'scheduled_at' => now()->subMinutes(7), 'finished_at' => now()->subMinutes(6),
        ]);
        $this->postJson(self::BASE, $this->sms(['priority' => 'high']))->assertAccepted();

        $this->get('/metrics')->assertOk()
            ->assertSee('notifications_queue_depth{priority="high"} 1', false)
            ->assertSee('notifications_outcomes_5m{channel="sms",outcome="failed"} 1', false)
            ->assertSee('notifications_outcome_ratio_5m{channel="sms",outcome="accepted"} 0.500000', false)
            ->assertSee('notifications_outcome_ratio_5m{channel="sms",outcome="failed"} 0.500000', false)
            ->assertSee('notifications_acceptance_latency_seconds_avg_5m{channel="sms"} 7.000000', false);
    }

    private function sms(array $overrides = []): array
    {
        return array_replace([
            'recipient' => '+15550102030',
            'channel' => 'sms',
            'content' => 'Your synthetic message',
        ], $overrides);
    }
}
