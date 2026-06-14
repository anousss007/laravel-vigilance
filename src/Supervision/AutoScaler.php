<?php

namespace Vigilance\Supervision;

use Closure;

/**
 * Pure scaling math (no I/O): given a supervisor's options, the current
 * process count per pool, and a way to read each pool's backlog (and optionally
 * its average runtime), decide the desired process count per pool — throttled
 * by balance_max_shift so changes are gradual.
 *
 * maxProcesses is the supervisor TOTAL; "auto" distributes it across pools by
 * share of load, "simple" splits it evenly, and balance=false runs one pool
 * sized to the backlog.
 */
class AutoScaler
{
    /**
     * @param  array<string, int>  $current  process count per pool key
     * @param  Closure(string): int  $sizeFor  backlog size for a pool key
     * @param  ?Closure(string): float  $runtimeFor  avg ms/job for a pool key (time strategy)
     * @return array<string, int> desired (throttled) process count per pool key
     */
    public function scale(SupervisorOptions $options, array $current, Closure $sizeFor, ?Closure $runtimeFor = null): array
    {
        $desired = $this->desiredPerPool($options, $sizeFor, $runtimeFor);

        $out = [];
        foreach ($desired as $pool => $target) {
            $out[$pool] = $this->shift($current[$pool] ?? 0, $target, $options);
        }

        return $out;
    }

    /**
     * The untthrottled target per pool (before balance_max_shift is applied).
     *
     * @param  Closure(string): int  $sizeFor
     * @param  ?Closure(string): float  $runtimeFor
     * @return array<string, int>
     */
    public function desiredPerPool(SupervisorOptions $options, Closure $sizeFor, ?Closure $runtimeFor = null): array
    {
        $pools = $options->pools();

        // Non-balancing: a single pool sized to its backlog, clamped to [min, max].
        if (! $options->balancing()) {
            $key = $pools[0];
            $size = max(0, $sizeFor($key));

            return [$key => min($options->maxProcesses, max($options->minProcesses, $size))];
        }

        // Simple: split the total evenly across pools (each at least min).
        if (! $options->autoScaling()) {
            $each = max($options->minProcesses, intdiv($options->maxProcesses, max(1, count($pools))));

            return array_fill_keys($pools, $each);
        }

        // Auto: distribute the total by each pool's share of the load.
        $weights = [];
        $total = 0.0;
        $anyJobs = false;

        foreach ($pools as $key) {
            $size = max(0, $sizeFor($key));
            $anyJobs = $anyJobs || $size > 0;

            $weight = ($options->autoScaleByNumberOfJobs() || $runtimeFor === null)
                ? (float) $size
                : $size * max(0.0, $runtimeFor($key));

            $weights[$key] = $weight;
            $total += $weight;
        }

        $out = [];
        foreach ($pools as $key) {
            if ($total > 0.0) {
                $out[$key] = max($options->minProcesses, (int) round(($weights[$key] / $total) * $options->maxProcesses));
            } else {
                // No measurable load anywhere: idle at min.
                $out[$key] = $options->minProcesses;
            }
        }

        return $out;
    }

    /**
     * Move current → target by at most balanceMaxShift this tick, never below
     * minProcesses.
     */
    protected function shift(int $current, int $target, SupervisorOptions $options): int
    {
        $maxShift = max(1, $options->balanceMaxShift);

        if ($target > $current) {
            return min($target, $current + $maxShift);
        }

        if ($target < $current) {
            return max($target, $current - $maxShift, $options->minProcesses);
        }

        return $current;
    }
}
