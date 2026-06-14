<?php

namespace Vigilance\Metrics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Vigilance\Contracts\MetricsRepository;
use Vigilance\Enums\RunStatus;
use Vigilance\Models\Run;
use Vigilance\Models\ScheduledTaskMonitor;
use Vigilance\Models\WorkerRecord;

/**
 * Assembles the "workload" view model: one row per queue Vigilance has seen
 * recently (with live depth + last-hour throughput), plus the scheduled-task
 * health table.
 */
class Workload
{
    public function __construct(
        protected QueueDepth $queueDepth,
        protected MetricsRepository $metrics,
    ) {}

    /**
     * One row per (connection, queue) seen in the last 24h.
     *
     * @return list<array{
     *     connection_name: ?string,
     *     queue: string,
     *     depth: ?int,
     *     workers: int,
     *     processed_last_hour: int,
     *     failed_last_hour: int,
     *     avg_runtime_ms: ?int,
     *     avg_wait_ms: ?int,
     *     time_to_clear_ms: ?int,
     *     series: Collection<int, object>
     * }>
     */
    public function queues(): array
    {
        $dayAgo = Carbon::now()->subDay();
        $hourAgo = Carbon::now()->subHour();

        $workerCounts = WorkerRecord::query()
            ->selectRaw('connection, queue, count(*) as c')
            ->groupBy('connection', 'queue')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->connection.'|'.$r->queue => (int) $r->getAttribute('c')]);

        $pairs = Run::query()
            ->whereNotNull('queue')
            ->where('created_at', '>=', $dayAgo)
            ->select('connection_name', 'queue')
            ->distinct()
            ->orderBy('connection_name')
            ->orderBy('queue')
            ->get();

        $rows = [];

        foreach ($pairs as $pair) {
            $queue = (string) $pair->queue;
            $connection = $pair->connection_name !== null ? (string) $pair->connection_name : null;

            $lastHour = Run::query()
                ->where('queue', $queue)
                ->when(
                    $connection !== null,
                    fn ($q) => $q->where('connection_name', $connection),
                    fn ($q) => $q->whereNull('connection_name'),
                )
                ->where('finished_at', '>=', $hourAgo)
                ->whereIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value])
                ->selectRaw('count(*) as processed')
                ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [RunStatus::Failed->value])
                ->selectRaw('avg(duration_ms) as runtime')
                ->selectRaw('avg(wait_ms) as waited')
                ->first();

            $processed = (int) ($lastHour?->getAttribute('processed') ?? 0);
            $failed = (int) ($lastHour?->getAttribute('failed') ?? 0);
            $runtime = $lastHour?->getAttribute('runtime');
            $waited = $lastHour?->getAttribute('waited');

            $depth = $connection !== null ? $this->queueDepth->for($connection, $queue) : null;
            $avgRuntime = $runtime !== null ? (int) round((float) $runtime) : null;
            $workers = $workerCounts[$connection.'|'.$queue] ?? 0;

            $rows[] = [
                'connection_name' => $connection,
                'queue' => $queue,
                'depth' => $depth,
                'workers' => $workers,
                'processed_last_hour' => $processed,
                'failed_last_hour' => $failed,
                'avg_runtime_ms' => $avgRuntime,
                'avg_wait_ms' => $waited !== null ? (int) round((float) $waited) : null,
                'time_to_clear_ms' => ($depth !== null && $avgRuntime !== null)
                    ? (int) round($depth * $avgRuntime / max(1, $workers))
                    : null,
                'series' => $this->metrics->series('queue', $queue),
            ];
        }

        return $rows;
    }

    /**
     * System load average (1, 5, 15 min). Null on platforms without
     * sys_getloadavg() (e.g. Windows), so the view can show "n/a" honestly.
     *
     * @return array{1:float,5:float,15:float}|null
     */
    public function load(): ?array
    {
        if (! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();

        if ($load === false) {
            return null;
        }

        return [
            1 => round((float) $load[0], 2),
            5 => round((float) $load[1], 2),
            15 => round((float) $load[2], 2),
        ];
    }

    /**
     * Every scheduled-task monitor with derived health flags.
     *
     * @return Collection<int, ScheduledTaskMonitor>
     */
    public function scheduledTasks(): Collection
    {
        return ScheduledTaskMonitor::query()
            ->orderBy('name')
            ->get()
            ->each(function (ScheduledTaskMonitor $task): void {
                $task->setAttribute('is_late', $task->isLate());
                $task->setAttribute('last_run_failed', $task->lastRunFailed());
            });
    }
}
