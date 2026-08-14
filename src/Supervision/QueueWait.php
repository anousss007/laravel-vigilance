<?php

namespace Vigilance\Supervision;

use Illuminate\Support\Carbon;
use Vigilance\Enums\RunStatus;
use Vigilance\Models\Run;

/**
 * The wait time jobs on a queue actually experienced, in milliseconds.
 *
 * This is measured, not modelled. The "time" strategy weights a pool by
 * backlog x average runtime, which is an *estimate* of how long the queue will
 * take to drain — and estimates built on an average break exactly when they
 * matter: a mix of 50 ms and 50 s jobs has a meaningless mean, and one slow job
 * type arriving skews it for an hour. wait_ms is recorded per run from queued_at
 * to start, so it already knows the answer.
 *
 * Uses a high percentile rather than the mean for the same reason: scaling to
 * the average wait leaves the tail — the requests people actually complain
 * about — unserved.
 *
 * Cached briefly because the supervisor loop ticks once a second and must not
 * re-query per tick.
 */
class QueueWait
{
    /** @var array<string, array{0: float, 1: int}> connection|queue => [ms, expiresAt] */
    protected array $cache = [];

    protected int $ttl = 10;

    /**
     * Recent p90 wait for the queue, or 0.0 when nothing has run recently
     * (an idle queue has no latency problem to solve).
     */
    public function for(string $connection, string $queue): float
    {
        $key = $connection.'|'.$queue;
        $now = time();

        if (isset($this->cache[$key]) && $this->cache[$key][1] > $now) {
            return $this->cache[$key][0];
        }

        $waits = Run::query()
            ->where('connection_name', $connection)
            ->where('queue', $queue)
            ->whereIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value])
            ->where('finished_at', '>=', Carbon::now()->subMinutes(5))
            ->whereNotNull('wait_ms')
            // Bounded: the supervisor must not pull an unbounded result set into
            // memory on a busy queue just to take a percentile.
            ->orderByDesc('id')
            ->limit(200)
            ->pluck('wait_ms')
            ->map(fn ($value) => (float) $value)
            ->sort()
            ->values()
            ->all();

        $wait = $this->quantile($waits, 0.90);

        $this->cache[$key] = [$wait, $now + $this->ttl];

        return $wait;
    }

    /**
     * Nearest-rank percentile of a pre-sorted list.
     *
     * @param  list<float>  $sorted
     */
    protected function quantile(array $sorted, float $q): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        $rank = (int) ceil($q * $count) - 1;

        return $sorted[max(0, min($count - 1, $rank))];
    }
}
