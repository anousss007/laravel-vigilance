<?php

namespace Vigilance\Supervision;

use Vigilance\Metrics\QueueDepth;

/**
 * Runs and scales the worker pools for ONE configured supervisor block, and
 * reacts to the control plane (pause / restart / terminate). One tick() per
 * second is driven by the supervise command.
 */
class Supervisor
{
    /** @var array<string, Pool> */
    protected array $pools = [];

    protected ?string $bootRestartToken;

    protected bool $working = true;

    protected ?int $lastScaledAt = null;

    public function __construct(
        public SupervisorOptions $options,
        protected AutoScaler $scaler,
        protected SupervisorState $state,
        protected ControlPlane $control,
        protected QueueDepth $depth,
        protected ?QueueRuntime $runtime = null,
    ) {
        foreach ($options->pools() as $key) {
            $this->pools[$key] = new Pool($key, $options);
        }

        $this->bootRestartToken = $control->restartToken();
    }

    /**
     * One supervision iteration. Returns false once the supervisor has fully
     * terminated and should be dropped from the loop.
     */
    public function tick(): bool
    {
        if ($this->control->isTerminating()) {
            $this->terminate();

            return false;
        }

        $token = $this->control->restartToken();

        if ($token !== $this->bootRestartToken) {
            $this->bootRestartToken = $token;
            $this->restart();
        }

        $this->working = ! $this->control->isPaused();

        if ($this->working) {
            // Per-queue pause: narrow each pool to its still-active queues. Pools
            // whose every queue is paused are held at zero; the rest scale and
            // monitor as usual.
            $activePools = $this->applyQueuePauses();

            $this->autoScale($activePools);

            foreach ($this->pools as $key => $pool) {
                if (in_array($key, $activePools, true)) {
                    $pool->monitor();
                } else {
                    $pool->scaleTo(0);
                }
            }
        } else {
            foreach ($this->pools as $pool) {
                $pool->scaleTo(0);
            }
        }

        $this->heartbeat();

        return true;
    }

    /**
     * Push the current per-queue pause state down to the pools and report which
     * pools still have work to do. A pool serving several queues (balance=false)
     * is narrowed to just its unpaused queues; a pool whose queues are all paused
     * is omitted from the returned list so tick() holds it at zero workers.
     *
     * @return list<string> keys of pools with at least one active queue
     */
    protected function applyQueuePauses(): array
    {
        $active = [];

        foreach ($this->pools as $key => $pool) {
            $live = array_values(array_filter(
                explode(',', $key),
                fn (string $queue) => ! $this->control->isQueuePaused($this->options->connection, $queue),
            ));

            $pool->setActiveQueues(implode(',', $live));

            if ($live !== []) {
                $active[] = $key;
            }
        }

        return $active;
    }

    public function terminate(): void
    {
        foreach ($this->pools as $pool) {
            $pool->terminate();
        }

        $this->state->forget($this->options->name);
    }

    /**
     * Clear any workers orphaned by a previous master (hard kill / OOM / a
     * non-cgroup process manager restart) before this supervisor launches its
     * own pools. Called once at boot, where the pools are guaranteed empty.
     */
    public function reapOrphans(): void
    {
        foreach ($this->pools as $pool) {
            $pool->reapOrphans();
        }
    }

    /**
     * Live worker PIDs across all pools.
     *
     * @return list<int>
     */
    public function workerPids(): array
    {
        $pids = [];

        foreach ($this->pools as $pool) {
            $pids = array_merge($pids, $pool->pids());
        }

        return $pids;
    }

    /**
     * Live worker count per pool key.
     *
     * @return array<string, int>
     */
    public function poolCounts(): array
    {
        $out = [];

        foreach ($this->pools as $key => $pool) {
            $out[$key] = $pool->count();
        }

        return $out;
    }

    /**
     * Total worker processes currently running across all pools.
     */
    public function totalProcesses(): int
    {
        return array_sum(array_map(fn (Pool $p) => $p->count(), $this->pools));
    }

    /**
     * The queues each pool is currently serving after per-queue pauses are
     * applied (pool key => active queue list, '' when the pool is fully paused).
     * Reflects the live decision the last tick() acted on.
     *
     * @return array<string, string>
     */
    public function poolActiveQueues(): array
    {
        return array_map(fn (Pool $p): string => $p->activeQueues(), $this->pools);
    }

    /**
     * @param  list<string>  $activePools  pool keys eligible to scale this tick
     *                                     (paused-out pools are handled by tick())
     */
    protected function autoScale(array $activePools): void
    {
        // Throttle how often the desired pool sizes are re-evaluated: at most once
        // per balance_cooldown seconds. Between evaluations the pools hold steady
        // (dead workers are still respawned by monitor()), so scaling is gradual
        // and doesn't thrash on a bursty backlog.
        $now = time();

        if (! static::cooldownElapsed($this->lastScaledAt, $now, $this->options->balanceCooldown)) {
            return;
        }

        $this->lastScaledAt = $now;

        $current = [];

        foreach ($this->pools as $key => $pool) {
            $current[$key] = $pool->count();
        }

        $desired = $this->scaler->scale(
            $this->options,
            $current,
            fn (string $pool): int => $this->sizeFor($pool),
            fn (string $pool): float => $this->runtimeFor($pool),
        );

        // Only resize pools that still have an active queue this tick — never
        // spin up workers for a paused pool just to tear them down again below.
        foreach ($desired as $key => $target) {
            if (in_array($key, $activePools, true)) {
                $this->pools[$key]->scaleTo($target);
            }
        }
    }

    /**
     * Whether enough time has passed since the last scaling evaluation. Pure so
     * it can be unit-tested without a running supervisor.
     */
    public static function cooldownElapsed(?int $lastScaledAt, int $now, int $cooldown): bool
    {
        return $lastScaledAt === null || ($now - $lastScaledAt) >= max(0, $cooldown);
    }

    protected function sizeFor(string $poolKey): int
    {
        $total = 0;

        foreach (explode(',', $poolKey) as $queue) {
            $total += $this->depth->for($this->options->connection, $queue) ?? 0;
        }

        return $total;
    }

    /**
     * Average ms/job across the queues in a pool (for the "time" strategy).
     * Returns 1.0 when no runtime source/data is available, so weighting falls
     * back to pure backlog size.
     */
    protected function runtimeFor(string $poolKey): float
    {
        if ($this->runtime === null) {
            return 1.0;
        }

        $queues = explode(',', $poolKey);
        $sum = 0.0;

        foreach ($queues as $queue) {
            $sum += $this->runtime->for($this->options->connection, $queue);
        }

        return $sum / max(1, count($queues));
    }

    protected function heartbeat(): void
    {
        $pools = [];
        $workers = [];

        foreach ($this->pools as $key => $pool) {
            $pools[$key] = $pool->count();
            $workers = array_merge($workers, $pool->descriptors());
        }

        $this->state->heartbeat(
            $this->options,
            $this->working ? 'running' : 'paused',
            $pools,
            $workers,
        );
    }

    /**
     * Rolling restart: gracefully stop current workers; autoScale respawns
     * fresh processes (picking up new code) on the next tick.
     */
    protected function restart(): void
    {
        foreach ($this->pools as $pool) {
            $pool->terminate();
        }
    }
}
