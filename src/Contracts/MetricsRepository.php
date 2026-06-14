<?php

namespace Vigilance\Contracts;

use Illuminate\Support\Collection;

interface MetricsRepository
{
    /**
     * Aggregate runs in the given window and persist one snapshot row per
     * job name and per queue.
     */
    public function snapshot(\DateTimeInterface $since, \DateTimeInterface $until): void;

    /**
     * Recent snapshot points for a scope ("job" or "queue"), newest last.
     *
     * @return Collection<int, object>
     */
    public function series(string $scopeType, string $scope, int $limit = 60): Collection;

    /**
     * Trim each scope's snapshot series to the configured ring-buffer size.
     */
    public function trim(int $keep): void;
}
