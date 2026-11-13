<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class OperationsController
{
    private const OUTCOMES = ['accepted', 'failed', 'uncertain'];

    public function health(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::selectOne('select 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
        ];
        $healthy = ! in_array('down', $checks, true);

        return response()->json(['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks], $healthy ? 200 : 503);
    }

    public function metrics(): Response
    {
        $queueDepth = DB::table('jobs')->selectRaw('queue, count(*) as total')->groupBy('queue')->pluck('total', 'queue');
        $elapsed = match (DB::connection()->getDriverName()) {
            'pgsql' => 'extract(epoch from (finished_at - scheduled_at))',
            'sqlite' => "(strftime('%s', finished_at) - strftime('%s', scheduled_at))",
        };
        $outcomes = DB::table('notifications')->whereIn('status', self::OUTCOMES)
            ->where('finished_at', '>=', now()->subMinutes(5))
            ->selectRaw("channel, status, count(*) as total, sum($elapsed) as elapsed_seconds")
            ->groupBy('channel', 'status')->get()->keyBy(fn ($row) => $row->channel.':'.$row->status);

        $lines = [
            '# HELP notifications_queue_depth Jobs retained in the queue, including scheduled and reserved work.',
            '# TYPE notifications_queue_depth gauge',
        ];
        foreach (Notification::PRIORITIES as $priority) {
            $lines[] = sprintf('notifications_queue_depth{priority="%s"} %d', $priority, $queueDepth[$priority] ?? 0);
        }
        $lines[] = '# HELP notifications_outcomes_5m Terminal delivery outcomes in the last five minutes.';
        $lines[] = '# TYPE notifications_outcomes_5m gauge';
        foreach (Notification::CHANNELS as $channel) {
            foreach (self::OUTCOMES as $status) {
                $lines[] = sprintf('notifications_outcomes_5m{channel="%s",outcome="%s"} %d',
                    $channel, $status, $outcomes->get("$channel:$status")->total ?? 0);
            }
        }
        $lines[] = '# HELP notifications_outcome_ratio_5m Outcome share from 0 to 1, or NaN when there are no outcomes.';
        $lines[] = '# TYPE notifications_outcome_ratio_5m gauge';
        foreach (Notification::CHANNELS as $channel) {
            $counts = collect(self::OUTCOMES)->mapWithKeys(
                fn ($status) => [$status => (int) ($outcomes->get("$channel:$status")->total ?? 0)],
            );
            $total = $counts->sum();
            foreach ($counts as $status => $count) {
                $lines[] = sprintf('notifications_outcome_ratio_5m{channel="%s",outcome="%s"} %s',
                    $channel, $status, $total > 0 ? sprintf('%.6F', $count / $total) : 'NaN');
            }
        }
        $lines[] = '# HELP notifications_acceptance_latency_seconds_avg_5m Mean seconds from scheduled time to provider acceptance over five minutes.';
        $lines[] = '# TYPE notifications_acceptance_latency_seconds_avg_5m gauge';
        foreach (Notification::CHANNELS as $channel) {
            $accepted = $outcomes->get("$channel:accepted");
            $lines[] = sprintf('notifications_acceptance_latency_seconds_avg_5m{channel="%s"} %s',
                $channel, $accepted ? sprintf('%.6F', $accepted->elapsed_seconds / $accepted->total) : 'NaN');
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'up';
        } catch (Throwable) {
            return 'down';
        }
    }
}
