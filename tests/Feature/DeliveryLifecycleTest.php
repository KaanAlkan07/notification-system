<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Notifications\Delivery\ChannelRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeliveryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.provider_url' => 'https://provider.test/notifications']);
        Http::preventStrayRequests();
    }

    public function test_provider_acceptance_is_persisted_and_job_replay_does_not_send_twice(): void
    {
        $this->allowSend();
        $notification = $this->notification();
        $messageId = (string) Str::uuid();
        Http::fake(['provider.test/*' => Http::response([
            'messageId' => $messageId,
            'status' => 'accepted',
            'timestamp' => '2026-01-01T00:00:00Z',
        ], 202)]);

        $this->runDelivery($notification);
        $this->runDelivery($notification);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key', $notification->id)
            && $request->hasHeader('X-Correlation-ID', $notification->correlation_id)
            && $request->data() === [
                'to' => $notification->recipient,
                'channel' => $notification->channel,
                'content' => $notification->content,
            ]);
        $this->assertSame('accepted', $notification->refresh()->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame($messageId, $notification->provider_message_id);
        $this->assertNotNull($notification->finished_at);
    }

    public function test_throttling_leaves_provider_attempt_budget_untouched(): void
    {
        $this->mock(ChannelRateLimiter::class)
            ->shouldReceive('acquire')->once()->with('sms', \Mockery::type('string'))->andReturnFalse();
        $notification = $this->notification();

        $job = $this->runDelivery($notification);

        $job->assertReleased();
        Http::assertNothingSent();
        $this->assertSame('pending', $notification->refresh()->status);
        $this->assertSame(0, $notification->attempts);
    }

    public function test_only_the_current_retry_cycle_can_deliver(): void
    {
        $this->allowSend();
        $notification = $this->notification(['retry_generation' => 1]);
        Http::fake(['provider.test/*' => Http::response([
            'messageId' => (string) Str::uuid(),
            'status' => 'accepted',
            'timestamp' => '2026-01-01T00:00:00Z',
        ], 202)]);

        $this->runDelivery($notification);
        Http::assertNothingSent();

        $this->runDelivery($notification, 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $notification->id.':1'));
        $this->assertSame('accepted', $notification->refresh()->status);
    }

    public function test_rate_limited_provider_retry_survives_early_redelivery_and_keeps_its_key(): void
    {
        $this->freezeSecond();
        $this->allowSend();
        $notification = $this->notification();
        Http::fake(['provider.test/*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '17'])
            ->push([
                'messageId' => (string) Str::uuid(),
                'status' => 'accepted',
                'timestamp' => '2026-01-01T00:00:00+00:00',
            ], 202)]);

        $this->runDelivery($notification)->assertReleased(17);

        $this->assertSame('retrying', $notification->refresh()->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertTrue($notification->next_attempt_at->equalTo(now()->addSeconds(17)));

        $this->runDelivery($notification)->assertReleased(17);
        Http::assertSentCount(1);
        $this->travel(17)->seconds();
        $this->runDelivery($notification);

        Http::assertSentCount(2);
        $this->assertTrue(Http::recorded()->every(
            fn (array $record) => $record[0]->hasHeader('Idempotency-Key', $notification->id),
        ));
        $this->assertSame('accepted', $notification->refresh()->status);
        $this->assertSame(2, $notification->attempts);
    }

    public function test_retry_budget_is_enforced_for_explicit_refusals(): void
    {
        $this->allowSend();
        $notification = $this->notification(['attempts' => 4]);
        config(['notifications.max_attempts' => 5]);
        Http::fake(['provider.test/*' => Http::response([], 503)]);

        $this->runDelivery($notification)->assertNotReleased();

        $this->assertSame('failed', $notification->refresh()->status);
        $this->assertSame(5, $notification->attempts);
        $this->assertSame('http_503', $notification->failure_code);
    }

    public function test_timeout_is_uncertain_and_is_never_automatically_resent(): void
    {
        $this->allowSend();
        $notification = $this->notification();
        Http::fake(['provider.test/*' => Http::failedConnection()]);

        $this->runDelivery($notification)->assertNotReleased();
        $this->runDelivery($notification)->assertNotReleased();

        $this->assertSame('uncertain', $notification->refresh()->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame('transport_error', $notification->failure_code);
    }

    public function test_malformed_acceptance_rejects_invalid_calendar_values(): void
    {
        $this->allowSend();
        $notification = $this->notification();
        Http::fake(['provider.test/*' => Http::response([
            'messageId' => (string) Str::uuid(),
            'status' => 'accepted',
            'timestamp' => '2026-02-30T10:00:00Z',
        ], 202)]);

        $this->runDelivery($notification)->assertNotReleased();

        $this->assertSame('uncertain', $notification->refresh()->status);
        $this->assertSame('invalid_acceptance', $notification->failure_code);
    }

    public function test_cancelled_notifications_never_reach_the_provider(): void
    {
        $this->mock(ChannelRateLimiter::class)->shouldNotReceive('acquire');
        $notification = $this->notification(['status' => 'cancelled']);

        $this->runDelivery($notification)->assertNotReleased();

        Http::assertNothingSent();
        $this->assertSame('cancelled', $notification->refresh()->status);
    }

    public function test_job_waits_for_scheduled_delivery(): void
    {
        $this->freezeSecond();
        $this->mock(ChannelRateLimiter::class)->shouldNotReceive('acquire');
        $notification = $this->notification(['scheduled_at' => now()->addMinute()]);

        $this->runDelivery($notification)->assertReleased(60);

        Http::assertNothingSent();
        $this->assertSame('pending', $notification->refresh()->status);
    }

    public function test_early_redelivery_preserves_recovery_until_the_claim_expires(): void
    {
        $this->freezeSecond();
        $this->mock(ChannelRateLimiter::class)->shouldNotReceive('acquire');
        $notification = $this->notification([
            'attempts' => 1,
            'status' => 'processing',
            'processing_started_at' => now(),
        ]);

        $this->travel(5)->seconds();
        $this->runDelivery($notification)->assertReleased(35);
        $this->assertSame('processing', $notification->refresh()->status);

        $this->travel(35)->seconds();
        $this->runDelivery($notification)->assertNotReleased();

        Http::assertNothingSent();
        $this->assertSame('uncertain', $notification->refresh()->status);
        $this->assertSame('worker_lost', $notification->failure_code);
    }

    private function allowSend(): void
    {
        $limiter = $this->mock(ChannelRateLimiter::class);
        $limiter->shouldReceive('acquire')->with('sms', \Mockery::type('string'))->andReturnTrue();
        $limiter->shouldReceive('complete')->with('sms', \Mockery::type('string'));
    }

    private function notification(array $overrides = []): Notification
    {
        return Notification::unguarded(fn () => Notification::query()->create(array_replace([
            'id' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'recipient' => '+905551234567',
            'channel' => 'sms',
            'content' => 'A test notification',
            'priority' => 'normal',
            'status' => 'pending',
            'attempts' => 0,
            'scheduled_at' => now(),
        ], $overrides)));
    }

    private function runDelivery(Notification $notification, int $retryGeneration = 0): DeliverNotification
    {
        $job = (new DeliverNotification($notification->id, $retryGeneration))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        return $job;
    }
}
