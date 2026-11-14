<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Notifications\Delivery\ChannelRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

final class TwilioDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SID = 'SM01234567890123456789012345678901';

    private const TOKEN = 'test-callback-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notifications.sms_provider' => 'twilio',
            'notifications.twilio.account_sid' => $this->accountSid(),
            'notifications.twilio.auth_token' => self::TOKEN,
            'notifications.twilio.from' => '+15550102031',
            'notifications.twilio.callback_base_url' => 'https://callbacks.example.test',
        ]);
        $limiter = $this->mock(ChannelRateLimiter::class);
        $limiter->shouldReceive('acquire')->andReturnTrue();
        $limiter->shouldReceive('complete');
        Http::preventStrayRequests();
    }

    public function test_sms_receipt_distinguishes_acceptance_from_delivery_and_ignores_stale_callbacks(): void
    {
        $id = $this->createSms();
        Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => self::SID, 'status' => 'queued'], 201)]);
        $this->send($id);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/'.$this->accountSid().'/Messages.json'
            && $request['To'] === '+15550102030' && $request['From'] === '+15550102031'
            && $request['StatusCallback'] === 'https://callbacks.example.test'.$this->callbackPath($id));

        $this->getJson('/api/v1/notifications/'.$id)->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.delivery_status', 'awaiting_receipt');

        $this->receipt($id, ['MessageStatus' => 'delivered'], valid: false)->assertForbidden();
        $this->receipt($id, ['MessageStatus' => 'delivered'], attempt: 2)->assertNoContent();
        $this->getJson('/api/v1/notifications/'.$id)->assertJsonPath('data.delivery_status', 'awaiting_receipt');
        $this->receipt($id, ['MessageStatus' => 'sent'])->assertNoContent();
        $this->getJson('/api/v1/notifications/'.$id)->assertJsonPath('data.delivery_status', 'sent');
        $this->receipt($id, ['MessageStatus' => 'delivered'])->assertNoContent();
        $this->receipt($id, ['MessageStatus' => 'sent'])->assertNoContent();
        $this->getJson('/api/v1/notifications?delivery_status=delivered')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/notifications/'.$id)->assertJsonPath('data.delivery_status', 'delivered')
            ->assertJsonPath('data.provider_message_id', self::SID);
    }

    public function test_receipt_arriving_before_the_http_response_preserves_its_failure_code(): void
    {
        $id = $this->createSms();
        Http::fake(['https://api.twilio.com/*' => function () use ($id) {
            $this->receipt($id, ['MessageStatus' => 'undelivered', 'ErrorCode' => '30003'])->assertNoContent();

            return Http::response(['sid' => self::SID, 'status' => 'queued'], 201);
        }]);
        $this->send($id);

        $this->getJson('/api/v1/notifications/'.$id)->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.delivery_status', 'undelivered')
            ->assertJsonPath('data.delivery_error_code', '30003');
    }

    private function createSms(): string
    {
        return $this->postJson('/api/v1/notifications', [
            'channel' => 'sms', 'recipient' => '+15550102030', 'content' => 'Synthetic SMS',
        ])->assertAccepted()->json('data.id');
    }

    private function send(string $id): void
    {
        $job = (new DeliverNotification($id))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);
    }

    private function callbackPath(string $id, int $attempt = 1): string
    {
        return '/webhooks/twilio/sms/'.$id.'/0/'.$attempt;
    }

    private function receipt(string $id, array $fields, bool $valid = true, int $attempt = 1): TestResponse
    {
        $fields += ['AccountSid' => $this->accountSid(), 'MessageSid' => self::SID, 'ExtraTwilioField' => 'extra value'];
        $path = $this->callbackPath($id, $attempt);
        $signature = (new RequestValidator(self::TOKEN))->computeSignature('https://callbacks.example.test'.$path, $fields);

        return $this->call('POST', $path, $fields, server: [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_TWILIO_SIGNATURE' => $valid ? $signature : 'invalid',
        ], content: http_build_query($fields));
    }

    private function accountSid(): string
    {
        return 'AC'.md5('notification-system-twilio-test-account');
    }
}
