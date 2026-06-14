<?php

namespace Vigilance\Supervision;

use Illuminate\Support\Carbon;
use Vigilance\Enums\RunStatus;
use Vigilance\Models\Run;

/**
 * Average job runtime (ms) per connection+queue, used by the "time" auto-scaling
 * strategy to weight a pool by how long its backlog will actually take to clear
 * — not just how many jobs are waiting. Results are cached briefly so the
 * supervisor loop (one tick/second) doesn't re-query every tick.
 */
class QueueRuntime
{
    /** @var array<string, array{0: float, 1: int}> connection|queue => [ms, expiresAt] */
    protected array $cache = [];

    protected int $ttl = 10;

    /**
     * Average ms/job for the queue over the last hour. Falls back to 1.0 (so the
     * time strategy degrades to size-based weighting) until runtime data exists.
     */
    public function for(string $connection, string $queue): float
    {
        $key = $connection.'|'.$queue;
        $now = time();

        if (isset($this->cache[$key]) && $this->cache[$key][1] > $now) {
            return $this->cache[$key][0];
        }

        $avg = (float) (Run::query()
            ->where('connection_name', $connection)
            ->where('queue', $queue)
            ->whereIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value])
            ->where('finished_at', '>=', Carbon::now()->subHour())
            ->avg('duration_ms') ?? 0.0);

        $runtime = max(1.0, $avg);
        $this->cache[$key] = [$runtime, $now + $this->ttl];

        return $runtime;
    }
}
