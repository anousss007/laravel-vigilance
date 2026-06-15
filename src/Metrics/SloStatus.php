<?php

namespace Vigilance\Metrics;

/**
 * A computed service-level objective: the current SLI attainment, how much of
 * the error budget remains, and the short-window burn rate (how fast the budget
 * is being consumed relative to what the target sustains; >1 = too fast).
 */
class SloStatus
{
    public function __construct(
        public string $id,
        public string $name,
        public string $sli,
        public float $target,
        public int $windowDays,
        public float $current,
        public float $budgetRemaining,
        public float $burnRate,
        public int $events,
    ) {}

    /** no-data | healthy | at-risk | breaching */
    public function status(): string
    {
        if ($this->events === 0) {
            return 'no-data';
        }

        if ($this->current < $this->target) {
            return 'breaching';
        }

        if ($this->burnRate >= 2.0 || $this->budgetRemaining < 25.0) {
            return 'at-risk';
        }

        return 'healthy';
    }
}
