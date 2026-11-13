<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Notification;
use App\Notifications\Delivery\ChannelRateLimiter;
use App\Notifications\Delivery\ProviderResult;
use App\Notifications\Delivery\RetryPolicy;
use App\Notifications\Delivery\WebhookProvider;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Predis\PredisException;
use RedisException;

final class DeliverNotification implements ShouldQueue
{
    use Queueable;

    // Longer than the worker timeout and shorter than the queue reservation lifetime.
    private const CLAIM_EXPIRY_SECONDS = 40;

    public int $backoff = 5;

    public function __construct(public string $notificationId) {}

    public function handle(ChannelRateLimiter $limiter, WebhookProvider $provider, RetryPolicy $retryPolicy): void
    {
        try {
            $claim = $this->claim($limiter);
        } catch (PredisException|RedisException) {
            $this->release($this->backoff);
            Log::warning('delivery.limiter_unavailable', ['notification_id' => $this->notificationId]);

            return;
        }

        if (is_int($claim)) {
            $this->release($claim);

            return;
        }

        if ($claim === null) {
            return;
        }

        if ($claim->status === 'uncertain') {
            $this->logOutcome($claim, 'uncertain', 'worker_lost');

            return;
        }

        $result = $provider->send($claim);
        $this->recordOutcome($claim, $result, $retryPolicy);
    }

    private function claim(ChannelRateLimiter $limiter): Notification|int|null
    {
        return DB::transaction(function () use ($limiter): Notification|int|null {
            $notification = Notification::query()->lockForUpdate()->find($this->notificationId);

            if ($notification === null) {
                return null;
            }

            $now = now();

            if ($notification->status === 'processing') {
                return $this->recoverClaim($notification, $now);
            }

            if (! in_array($notification->status, ['pending', 'retrying'], true)) {
                return null;
            }

            $availableAt = $notification->next_attempt_at ?? $notification->scheduled_at;

            if ($availableAt !== null && $availableAt->greaterThan($now)) {
                return max(1, (int) ceil($now->diffInSeconds($availableAt, false)));
            }

            if (! $limiter->acquire($notification->channel)) {
                return random_int(1, 2);
            }

            $notification->forceFill([
                'status' => 'processing',
                'attempts' => $notification->attempts + 1,
                'next_attempt_at' => null,
                'processing_started_at' => now(),
            ])->save();

            return $notification;
        });
    }

    private function recoverClaim(Notification $notification, CarbonInterface $now): Notification|int
    {
        $expiresAt = $notification->processing_started_at?->addSeconds(self::CLAIM_EXPIRY_SECONDS);

        if ($expiresAt !== null && $expiresAt->greaterThan($now)) {
            // A worker exception can release the recovery job before the claim expires.
            return max(1, (int) ceil($now->diffInSeconds($expiresAt, false)));
        }

        $notification->forceFill([
            'status' => 'uncertain',
            'failure_code' => 'worker_lost',
            'processing_started_at' => null,
            'finished_at' => $now,
        ])->save();

        return $notification;
    }

    private function recordOutcome(Notification $notification, ProviderResult $result, RetryPolicy $retryPolicy): void
    {
        $now = now();
        $status = $result->status;
        $delay = null;

        if ($status === 'retrying') {
            if ($notification->attempts < (int) config('notifications.max_attempts', 5)) {
                $delay = $retryPolicy->delay($notification->attempts, $result->retryAfter, $now);
            }

            if ($delay === null) {
                $status = 'failed';
            }
        }

        $updated = Notification::query()
            ->whereKey($notification->id)
            ->where('status', 'processing')
            ->where('attempts', $notification->attempts)
            ->update([
                'status' => $status,
                'provider_message_id' => $result->messageId,
                'failure_code' => $result->failureCode,
                'next_attempt_at' => $delay === null ? null : $now->copy()->addSeconds($delay),
                'processing_started_at' => null,
                'finished_at' => $delay === null ? $now : null,
            ]);

        if ($updated === 0) {
            return;
        }

        if ($delay !== null) {
            $this->release($delay);
        }

        $this->logOutcome($notification, $status, $result->failureCode);
    }

    private function logOutcome(Notification $notification, string $status, ?string $failureCode): void
    {
        Log::log($status === 'accepted' ? 'info' : 'warning', 'delivery.'.$status, [
            'notification_id' => $notification->id,
            'correlation_id' => $notification->correlation_id,
            'channel' => $notification->channel,
            'priority' => $notification->priority,
            'attempt' => $notification->attempts,
            'failure_code' => $failureCode,
        ]);
    }
}
