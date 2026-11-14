<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Notifications\Delivery\ChannelRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class SmtpDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notifications.email_provider' => 'smtp',
            'mail.mailers.notification_smtp.host' => 'smtp.example.test',
            'mail.mailers.notification_smtp.username' => 'synthetic-user',
            'mail.mailers.notification_smtp.password' => 'synthetic-password',
            'mail.from.address' => 'sender@example.test',
        ]);
        $limiter = $this->mock(ChannelRateLimiter::class);
        $limiter->shouldReceive('acquire')->andReturnTrue();
        $limiter->shouldReceive('complete');
        Http::preventStrayRequests();
    }

    public function test_the_email_worker_uses_smtp_and_preserves_plain_text_and_recipient(): void
    {
        $notification = $this->notification();
        $smtp = $this->mock(TransportInterface::class);
        $smtp->shouldReceive('send')->once()->andReturnUsing(function (Email $message) use ($notification): SentMessage {
            $this->assertSame('sender@example.test', $message->getFrom()[0]->getAddress());
            $this->assertSame('recipient@example.test', $message->getTo()[0]->getAddress());
            $this->assertSame('Synthetic <plain> email', $message->getTextBody());
            $this->assertNull($message->getHtmlBody());
            $this->assertStringContainsString($notification->id.'.0@', $message->getHeaders()->get('Message-ID')->getBodyAsString());
            $result = new SentMessage($message, Envelope::create($message));
            $result->setMessageId('TestSmtpReceipt');

            return $result;
        });
        Mail::mailer('notification_smtp')->setSymfonyTransport($smtp);

        $this->runDelivery($notification);

        $this->assertSame('accepted', $notification->refresh()->status);
        $this->assertSame('TestSmtpReceipt', $notification->provider_message_id);
        Http::assertNothingSent();
    }

    public function test_an_explicit_temporary_rejection_retries_but_a_lost_reply_is_uncertain(): void
    {
        $notification = $this->notification();
        $smtp = $this->mock(TransportInterface::class);
        $smtp->shouldReceive('send')->once()->andThrow(new UnexpectedResponseException('Temporary SMTP rejection', 451));
        $smtp->shouldReceive('send')->once()->andThrow(new TransportException('Connection lost'));
        Mail::mailer('notification_smtp')->setSymfonyTransport($smtp);

        $this->runDelivery($notification)->assertReleased();
        $this->assertSame('retrying', $notification->refresh()->status);
        $this->assertSame('smtp_451', $notification->failure_code);
        $this->travel(10)->seconds();
        $this->runDelivery($notification)->assertNotReleased();
        $this->assertSame('uncertain', $notification->refresh()->status);
        $this->assertSame('smtp_transport_error', $notification->failure_code);
    }

    private function notification(): Notification
    {
        $id = $this->postJson('/api/v1/notifications', [
            'channel' => 'email',
            'recipient' => 'recipient@example.test',
            'content' => 'Synthetic <plain> email',
        ])->assertAccepted()->json('data.id');

        return Notification::query()->findOrFail($id);
    }

    private function runDelivery(Notification $notification): DeliverNotification
    {
        $job = (new DeliverNotification($notification->id))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        return $job;
    }
}
