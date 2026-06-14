<?php

namespace Vigilance\Metrics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;

/**
 * Read-only overview numbers for the dashboard home: window counts, a
 * per-minute throughput series, and recent failure/slow/failure-group lists.
 *
 * Everything here is DB-portable: aggregates use query-builder counts and the
 * per-minute series is bucketed in PHP from raw rows, so it works identically
 * on sqlite, mysql and pgsql.
 */
class Stats
{
    /**
     * Totals over a simple window ('1h', '24h', '7d', ...).
     *
     * @return array{total:int,succeeded:int,failed:int,running:int,queued:int,success_rate:float}
     */
    public function counts(string $window = '24h'): array
    {
        $since = $this->cutoff($window);

        $base = Run::query()->where('created_at', '>=', $since);

        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $get = static fn (RunStatus $status): int => (int) ($byStatus[$status->value] ?? 0);

        $succeeded = $get(RunStatus::Succeeded);
        $failed = $get(RunStatus::Failed);
        $running = $get(RunStatus::Running);
        $queued = $get(RunStatus::Queued);
        $total = (int) $byStatus->sum();

        // Success rate is over finished runs (succeeded + failed) only, so that
        // still-open runs don't drag the percentage down.
        $finished = $succeeded + $failed;
        $successRate = $finished > 0
            ? round(($succeeded / $finished) * 100, 1)
            : 0.0;

        return [
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'running' => $running,
            'queued' => $queued,
            'success_rate' => (float) $successRate,
        ];
    }

    /**
     * Per-minute throughput for the last N minutes, bucketed in PHP from rows
     * whose finished_at falls in the window. Empty minutes are zero-filled, so
     * the result is always exactly N buckets, oldest first.
     *
     * @return list<array{t:string,count:int,failed:int}>
     */
    public function throughputPerMinute(int $minutes = 60): array
    {
        $minutes = max(1, $minutes);

        // Floor "now" to the start of the current minute so bucket keys line up.
        $end = Carbon::now()->startOfMinute();
        $start = $end->copy()->subMinutes($minutes - 1);

        // Pre-seed every minute with zeros.
        $buckets = [];
        $order = [];
        for ($i = 0; $i < $minutes; $i++) {
            $minute = $start->copy()->addMinutes($i);
            $key = $minute->format('Y-m-d H:i');
            $buckets[$key] = ['t' => $minute->toIso8601String(), 'count' => 0, 'failed' => 0];
            $order[] = $key;
        }

        $rows = Run::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $start)
            ->get(['status', 'finished_at']);

        foreach ($rows as $row) {
            if (! $row->finished_at) {
                continue;
            }

            $key = $row->finished_at->copy()->startOfMinute()->format('Y-m-d H:i');

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['count']++;

            if ($row->status === RunStatus::Failed) {
                $buckets[$key]['failed']++;
            }
        }

        return array_map(static fn (string $key) => $buckets[$key], $order);
    }

    /**
     * Unresolved failure groups, most-recently-seen first.
     *
     * @return Collection<int, FailureGroup>
     */
    public function topFailing(int $limit = 5): Collection
    {
        return FailureGroup::query()
            ->whereNull('resolved_at')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'name', 'exception_class', 'message', 'occurrences', 'last_seen_at']);
    }

    /**
     * The slowest recent runs by measured duration.
     *
     * @return Collection<int, Run>
     */
    public function slowest(int $limit = 5): Collection
    {
        return Run::query()
            ->whereNotNull('duration_ms')
            ->orderByDesc('duration_ms')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'name', 'duration_ms', 'finished_at', 'type']);
    }

    /**
     * The most recent failed runs.
     *
     * @return Collection<int, Run>
     */
    public function recentFailures(int $limit = 10): Collection
    {
        return Run::query()
            ->failed()
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id', 'name', 'type', 'queue', 'connection_name',
                'exception_class', 'exception_message',
                'failure_group_id', 'finished_at', 'duration_ms',
            ]);
    }

    /**
     * Per-job-class performance breakdown over a window (jobs only): how many
     * ran, how often they failed, and their duration / memory / CPU profile.
     * The practical "which job is eating my server" view.
     *
     * @return list<array{name:string,runs:int,failed:int,fail_rate:float,avg_ms:int,max_ms:int,avg_memory:?int,avg_cpu:?int}>
     */
    public function byJobClass(string $window = '24h', int $limit = 30): array
    {
        $since = $this->cutoff($window);

        return Run::query()
            ->where('type', RunType::Job->value)
            ->where('created_at', '>=', $since)
            ->whereNotNull('name')
            ->groupBy('name')
            ->selectRaw('name')
            ->selectRaw('count(*) as runs')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [RunStatus::Failed->value])
            ->selectRaw('avg(duration_ms) as avg_ms')
            ->selectRaw('max(duration_ms) as max_ms')
            ->selectRaw('avg(memory_peak) as avg_memory')
            ->selectRaw('avg(cpu_time_ms) as avg_cpu')
            ->orderByDesc('runs')
            ->limit($limit)
            ->get()
            ->map(function (Run $row): array {
                $runs = (int) $row->getAttribute('runs');
                $failed = (int) $row->getAttribute('failed');
                $avgMemory = $row->getAttribute('avg_memory');
                $avgCpu = $row->getAttribute('avg_cpu');

                return [
                    'name' => (string) $row->name,
                    'runs' => $runs,
                    'failed' => $failed,
                    'fail_rate' => $runs > 0 ? round($failed / $runs * 100, 1) : 0.0,
                    'avg_ms' => (int) round((float) $row->getAttribute('avg_ms')),
                    'max_ms' => (int) $row->getAttribute('max_ms'),
                    'avg_memory' => $avgMemory !== null ? (int) round((float) $avgMemory) : null,
                    'avg_cpu' => $avgCpu !== null ? (int) round((float) $avgCpu) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Turn a simple window string ('1h', '24h', '7d', '90m', '2w') into a
     * cutoff. Falls back to 24h for anything unrecognised.
     */
    protected function cutoff(string $window): Carbon
    {
        $now = Carbon::now();

        if (! preg_match('/^(\d+)\s*(m|h|d|w)$/i', trim($window), $m)) {
            return $now->subDay();
        }

        $value = (int) $m[1];

        return match (strtolower($m[2])) {
            'm' => $now->subMinutes($value),
            'h' => $now->subHours($value),
            'd' => $now->subDays($value),
            'w' => $now->subWeeks($value),
            default => $now->subDay(),
        };
    }
}
