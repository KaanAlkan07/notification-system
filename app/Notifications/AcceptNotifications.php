<?php

namespace App\Notifications;

use App\Jobs\DeliverNotification;
use App\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class AcceptNotifications
{
    public function create(array $items, bool $batch, ?string $key, string $correlationId): string
    {
        $scope = $batch ? 'batch' : 'single';
        $id = (string) Str::uuid7();
        $now = CarbonImmutable::now()->startOfSecond();
        $created = DB::transaction(function () use ($items, $batch, $key, $correlationId, $id, $scope, $now): string {
            if ($key !== null) {
                $existing = $this->reserveKey($scope, $key, $items, $id, $now);
                if ($existing !== $id) {
                    return $existing;
                }
            }

            if ($batch) {
                DB::table('notification_batches')->insert([
                    'id' => $id, 'count' => count($items), 'created_at' => $now,
                ]);
            }

            $rows = [];
            $jobs = [];
            foreach ($items as $item) {
                $notificationId = $batch ? (string) Str::uuid7() : $id;
                $scheduled = RequestTimestamp::ceilToSecond($item['scheduled_at'] ?? $now)->max($now);
                $rows[] = array_replace($item, [
                    'id' => $notificationId,
                    'batch_id' => $batch ? $id : null,
                    'correlation_id' => $correlationId,
                    'scheduled_at' => $scheduled,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $jobs[$item['priority']][] = (new DeliverNotification($notificationId))->delay($scheduled);
            }

            foreach (array_chunk($rows, 75) as $chunk) {
                Notification::query()->insert($chunk);
            }
            $queue = Queue::connection('database');
            foreach ($jobs as $priority => $group) {
                foreach (array_chunk($group, 100) as $chunk) {
                    $queue->bulk($chunk, queue: $priority);
                }
            }

            return $id;
        }, attempts: 3);

        Log::info($created === $id ? 'notification.enqueued' : 'notification.idempotency_replayed', [
            'correlation_id' => $correlationId,
            $batch ? 'batch_id' : 'notification_id' => $created,
            'count' => count($items),
        ]);

        return $created;
    }

    private function reserveKey(string $scope, string $key, array $items, string $id, CarbonImmutable $now): string
    {
        $keyHash = hash('sha256', "$scope:$key");
        $payloadHash = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
        $table = DB::table('idempotency_records');
        $table->insertOrIgnore([
            'key_hash' => $keyHash,
            'payload_hash' => $payloadHash,
            'resource_id' => $id,
            'created_at' => $now,
        ]);
        $record = $table->where('key_hash', $keyHash)->first();
        abort_unless(hash_equals($record->payload_hash, $payloadHash), 409, 'Idempotency-Key was already used with different content.');

        return $record->resource_id;
    }
}
