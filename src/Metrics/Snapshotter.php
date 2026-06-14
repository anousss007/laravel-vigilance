<?php

namespace Vigilance\Metrics;

use Illuminate\Support\Carbon;
use Vigilance\Contracts\MetricsRepository;
use Vigilance\Models\MetricSnapshot;

/**
 * Thin orchestrator behind the "vigilance:snapshot" command. Works out the
 * window since the last persisted snapshot (or one interval back if there is
 * none), aggregates it, then trims each scope's ring buffer.
 */
class Snapshotter
{
    public function __construct(
        protected MetricsRepository $metrics,
    ) {}

    public function take(): void
    {
        $now = Carbon::now();

        $this->metrics->snapshot($this->since($now), $now);

        $keep = (int) config('vigilance.retention.snapshots', 60);

        $this->metrics->trim($keep);
    }

    /**
     * The window start: the latest existing snapshot's measured_at, else one
     * snapshot interval before now.
     */
    protected function since(Carbon $now): Carbon
    {
        $last = MetricSnapshot::query()
            ->orderByDesc('measured_at')
            ->value('measured_at');

        if ($last !== null) {
            return Carbon::instance($last instanceof \DateTimeInterface ? $last : Carbon::parse($last));
        }

        $interval = (int) config('vigilance.metrics.snapshot_interval_minutes', 5);

        return $now->copy()->subMinutes(max(1, $interval));
    }
}
