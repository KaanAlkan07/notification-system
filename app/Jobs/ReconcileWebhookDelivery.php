<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Notification;
use App\Notifications\Delivery\RetryPolicy;
use App\Notifications\Delivery\WebhookProvider;
use App\Notifications\RequestTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

final class ReconcileWebhookDelivery implements ShouldQueue
{
    use Queueable;

    private const POLL_DELAYS = [5, 10, 20, 40, 60];

    public int $tries = 6;

    public int $backoff = 5;

    public function __construct(
        public string $notificationId,
        public int $generation,
        public int $deliveryAttempt,
        public string $providerIdentity,
    ) {}

    public function handle(WebhookProvider $provider, RetryPolicy $retryPolicy): void
    {
        if ($this->providerIdentity !== $provider->reconciliationIdentity()) {
            return;
        }

        $notification = $this->unresolved()->first();
        if ($notification === null) {
            return;
        }

        $result = $provider->lookup($notification);
        if ($result === null) {
            $delay = self::POLL_DELAYS[$this->attempts() - 1] ?? null;
            if ($delay !== null) {
                $this->release($delay);
            } else {
                Log::warning('delivery.reconciliation_unresolved', ['notification_id' => $notification->id]);
            }

            return;
        }

        $now = CarbonImmutable::now();
        $delay = $result->status === 'retrying' && $this->deliveryAttempt < (int) config('notifications.max_attempts', 5)
            ? $retryPolicy->delay($this->deliveryAttempt, $result->retryAfter, $now)
            : null;
        $nextAttempt = $delay === null ? null : RequestTimestamp::ceilToSecond($now->addSeconds($delay));
        $status = $result->status === 'accepted' ? 'accepted' : ($nextAttempt === null ? 'failed' : 'retrying');

        $updated = DB::transaction(function () use ($notification, $result, $now, $nextAttempt, $status): int {
            $updated = $this->unresolved()->update([
                'status' => $status,
                'provider_message_id' => $result->messageId,
                'failure_code' => $result->failureCode,
                'next_attempt_at' => $nextAttempt,
                'finished_at' => $nextAttempt === null ? $now : null,
            ]);
            if ($updated > 0 && $nextAttempt !== null) {
                $job = (new DeliverNotification($notification->id, $this->generation))->delay($nextAttempt);
                Queue::connection('database')->push($job, queue: $notification->priority);
            }

            return $updated;
        }, attempts: 3);

        if ($updated > 0) {
            Log::info('delivery.reconciled', [
                'notification_id' => $notification->id,
                'correlation_id' => $notification->correlation_id,
                'channel' => $notification->channel,
                'attempt' => $this->deliveryAttempt,
                'status' => $status,
                'failure_code' => $result->failureCode,
            ]);
        }
    }

    private function unresolved(): Builder
    {
        return Notification::query()
            ->whereKey($this->notificationId)
            ->where('status', 'uncertain')
            ->where('webhook_provider_identity', $this->providerIdentity)
            ->where('retry_generation', $this->generation)
            ->where('attempts', $this->deliveryAttempt);
    }
}
