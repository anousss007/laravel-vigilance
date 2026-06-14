<?php

namespace Vigilance\Apm\Contracts;

use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Vigilance\Apm\Entry;
use Vigilance\Apm\Value;

/**
 * Persists and reads APM telemetry. Writes pre-aggregate entries into
 * time-bucketed rows; reads union the fresh "tail" (raw entries) with the
 * rolled buckets so the current partial window is accurate.
 */
interface Storage
{
    /**
     * @param  Collection<int, Entry|Value>  $items
     */
    public function store(Collection $items): void;

    public function trim(): void;

    /**
     * @param  list<string>|null  $types
     */
    public function purge(?array $types = null): void;

    /**
     * Latest-wins snapshot values for a type.
     *
     * @param  list<string>|null  $keys
     * @return Collection<string, object>
     */
    public function values(string $type, ?array $keys = null): Collection;

    /**
     * 60-point time series per key: key => type => [datetime => value].
     *
     * @param  list<string>  $types
     * @return Collection<string, Collection<string, Collection<string, int|null>>>
     */
    public function graph(array $types, string $aggregate, CarbonInterval $interval): Collection;

    /**
     * Top-N rows for a type with one or more aggregates per key.
     *
     * @param  string|list<string>  $aggregates
     * @return Collection<int, object>
     */
    public function aggregate(
        string $type,
        string|array $aggregates,
        CarbonInterval $interval,
        string $orderBy = 'max',
        string $direction = 'desc',
        int $limit = 101,
    ): Collection;

    /**
     * Single scalar total of an aggregate across the interval.
     *
     * @param  string|list<string>  $types
     */
    public function aggregateTotal(string|array $types, string $aggregate, CarbonInterval $interval): float;
}
