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
     * @param  ?Closure(string): float  $waitFor  measured wait ms for a pool key (latency strategy)
     * @return array<string, int> desired (throttled) process count per pool key
     */
    public function scale(SupervisorOptions $options, array $current, Closure $sizeFor, ?Closure $runtimeFor = null, ?Closure $waitFor = null): array
    {
        $desired = $this->desiredPerPool($options, $sizeFor, $runtimeFor, $waitFor);

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
     * @param  ?Closure(string): float  $waitFor
     * @return array<string, int>
     */
    public function desiredPerPool(SupervisorOptions $options, Closure $sizeFor, ?Closure $runtimeFor = null, ?Closure $waitFor = null): array
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
        $worstWait = 0.0;
        $latency = $options->autoScaleByLatency() && $waitFor !== null;

        foreach ($pools as $key) {
            $size = max(0, $sizeFor($key));

            if ($latency) {
                $wait = max(0.0, $waitFor($key));
                $worstWait = max($worstWait, $wait);

                // A queue with nothing waiting needs no share, however slow its
                // jobs were a moment ago.
                $weight = $size > 0 ? max($wait, 1.0) : 0.0;
            } else {
                $weight = ($options->autoScaleByNumberOfJobs() || $runtimeFor === null)
                    ? (float) $size
                    : $size * max(0.0, $runtimeFor($key));
            }

            $weights[$key] = $weight;
            $total += $weight;
        }

        // Latency-driven: the size of the fleet follows how far the measured
        // wait is from the target, so it comes back down on its own once
        // latency recovers. Every other strategy deploys the whole fleet the
        // moment anything is queued, and only ever redistributes it.
        $budget = $latency
            ? max($options->minProcesses * count($pools), (int) round(
                min(1.0, $worstWait / $options->targetWaitMs()) * $options->maxProcesses
            ))
            : $options->maxProcesses;

        if ($total <= 0.0) {
            // No measurable load anywhere: idle at min.
            return array_fill_keys($pools, $options->minProcesses);
        }

        return $this->distribute($weights, $budget, $options->minProcesses);
    }

    /**
     * Split a budget of processes across pools in proportion to their weights.
     *
     * Uses largest-remainder rather than rounding each share independently:
     * rounding per pool lets the parts sum to more than the whole (two pools at
     * an exact half of 5 both round up to 3, giving 6), and maxProcesses is
     * documented as the supervisor TOTAL — quietly exceeding it is how a fleet
     * ends up over its memory budget.
     *
     * The per-pool floor is applied after the split, so it can still push the
     * total above the budget — that is the floor doing its job, and it is
     * bounded by minProcesses x pool count.
     *
     * @param  array<string, float>  $weights
     * @return array<string, int>
     */
    protected function distribute(array $weights, int $budget, int $minProcesses): array
    {
        $total = array_sum($weights);
        $exact = [];
        $out = [];
        $assigned = 0;

        foreach ($weights as $key => $weight) {
            $share = ($weight / $total) * $budget;
            $exact[$key] = $share;
            $out[$key] = (int) floor($share);
            $assigned += $out[$key];
        }

        // Hand out the remainder to whoever was rounded down hardest.
        $remainders = [];
        foreach ($exact as $key => $share) {
            $remainders[$key] = $share - floor($share);
        }
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($assigned >= $budget) {
                break;
            }

            $out[$key]++;
            $assigned++;
        }

        foreach ($out as $key => $value) {
            $out[$key] = max($minProcesses, $value);
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
