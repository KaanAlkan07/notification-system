<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateNotificationRequest;
use App\Http\Requests\ListNotificationsRequest;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Notifications\AcceptNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class NotificationController
{
    public function store(CreateNotificationRequest $request, AcceptNotifications $accept): JsonResponse
    {
        $id = $accept->create($request->items(), false, $request->idempotencyKey(), $request->attributes->get('correlation_id'));

        return $this->show($id)->setStatusCode(202)->header('Location', '/api/v1/notifications/'.$id);
    }

    public function storeBatch(CreateNotificationRequest $request, AcceptNotifications $accept): JsonResponse
    {
        $id = $accept->create($request->items(), true, $request->idempotencyKey(), $request->attributes->get('correlation_id'));

        return response()->json(['data' => $this->batch($id)], 202, [
            'Location' => '/api/v1/notification-batches/'.$id,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => Notification::query()->findOrFail($id)]);
    }

    public function index(ListNotificationsRequest $request): JsonResponse
    {
        return response()->json($this->page(Notification::query(), $request->filters()));
    }

    public function showBatch(ListNotificationsRequest $request, string $id): JsonResponse
    {
        $batch = $this->batch($id);
        $query = Notification::query()->where('batch_id', $id);
        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $batch['status_counts'] = array_replace(array_fill_keys(Notification::STATUSES, 0), $counts->map(fn ($value) => (int) $value)->all());

        return response()->json([
            'data' => $batch,
            'notifications' => $this->page($query, $request->filters()),
        ]);
    }

    public function cancel(string $id): JsonResponse
    {
        $notification = DB::transaction(function () use ($id): Notification {
            $notification = Notification::query()->lockForUpdate()->findOrFail($id);
            if ($notification->status === 'cancelled') {
                return $notification;
            }
            abort_unless(in_array($notification->status, ['pending', 'retrying'], true), 409, 'The notification can no longer be cancelled.');
            $notification->update([
                'status' => 'cancelled',
                'next_attempt_at' => null,
                'finished_at' => now(),
            ]);

            return $notification;
        }, attempts: 3);

        if ($notification->wasChanged('status')) {
            Log::info('notification.cancelled', ['notification_id' => $id, 'notification_correlation_id' => $notification->correlation_id]);
        }

        return response()->json(['data' => $notification]);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $generation = (int) $request->validate(['retry_generation' => ['required', 'integer', 'min:0']])['retry_generation'];
        $notification = DB::transaction(function () use ($id, $generation): Notification {
            $notification = Notification::query()->lockForUpdate()->findOrFail($id);
            abort_unless($notification->status === 'failed' && $notification->retry_generation === $generation,
                409, 'The notification is no longer eligible for this retry.');

            $notification->update([
                'status' => 'pending',
                'retry_generation' => $generation + 1,
                'attempts' => 0,
                'scheduled_at' => now(),
                'next_attempt_at' => null,
                'processing_started_at' => null,
                'provider_message_id' => null,
                'delivery_status' => null,
                'delivery_error_code' => null,
                'delivery_updated_at' => null,
                'failure_code' => null,
                'finished_at' => null,
            ]);

            Queue::connection('database')->push(
                new DeliverNotification($id, $notification->retry_generation), queue: $notification->priority,
            );

            return $notification;
        }, attempts: 3);

        Log::info('notification.manual_retry_enqueued', [
            'notification_id' => $id,
            'notification_correlation_id' => $notification->correlation_id,
            'retry_generation' => $notification->retry_generation,
        ]);

        return response()->json(['data' => $notification], 202, ['Location' => '/api/v1/notifications/'.$id]);
    }

    private function batch(string $id): array
    {
        $batch = DB::table('notification_batches')->where('id', $id)->first();
        abort_if($batch === null, 404, 'The batch was not found.');

        return [
            'id' => $batch->id,
            'count' => (int) $batch->count,
            'created_at' => CarbonImmutable::parse($batch->created_at, 'UTC')->toISOString(),
        ];
    }

    private function page(Builder $query, array $filters): array
    {
        foreach (['status', 'delivery_status', 'channel'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $name => $operator) {
            if (isset($filters[$name])) {
                $query->where('created_at', $operator, $filters[$name]);
            }
        }
        $page = $query->orderByDesc('id')->cursorPaginate((int) ($filters['per_page'] ?? 25), cursor: $filters['cursor'] ?? null);

        return ['data' => $page->items(), 'next_cursor' => $page->nextCursor()?->encode()];
    }
}
