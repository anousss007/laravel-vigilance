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
            $this->autoScale();

            foreach ($this->pools as $pool) {
                $pool->monitor();
            }
        } else {
            foreach ($this->pools as $pool) {
                $pool->scaleTo(0);
            }
        }

        $this->heartbeat();

        return true;
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

    protected function autoScale(): void
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

        foreach ($desired as $key => $target) {
            $this->pools[$key]->scaleTo($target);
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
