<?php

namespace Vigilance\Metrics;

use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Vigilance\Apm\Contracts\Storage;

/**
 * Auto-discovers and summarises the custom metrics recorded via
 * Vigilance::increment() (counters) and Vigilance::gauge() (gauges) for the
 * Custom Metrics dashboard — totals/averages plus a sparkline per metric.
 */
class CustomMetrics
{
    public function __construct(protected Storage $storage) {}

    /**
     * @return Collection<int, CustomMetricStat>
     */
    public function all(CarbonInterval $interval): Collection
    {
        $out = new Collection;

        $counters = $this->storage->aggregate('metric_count', ['sum', 'count'], $interval, orderBy: 'sum', limit: 100);
        $countGraph = $this->storage->graph(['metric_count'], 'sum', $interval);

        foreach ($counters as $row) {
            $out->push(new CustomMetricStat(
                name: (string) $row->key,
                type: 'count',
                value: (int) $row->sum,
                peak: (int) $row->count,
                series: $this->series($countGraph, (string) $row->key, 'metric_count'),
            ));
        }

        $gauges = $this->storage->aggregate('metric_value', ['avg', 'max'], $interval, orderBy: 'avg', limit: 100);
        $valueGraph = $this->storage->graph(['metric_value'], 'avg', $interval);

        foreach ($gauges as $row) {
            $out->push(new CustomMetricStat(
                name: (string) $row->key,
                type: 'value',
                value: (int) round((float) $row->avg),
                peak: (int) $row->max,
                series: $this->series($valueGraph, (string) $row->key, 'metric_value'),
            ));
        }

        return $out->sortBy('name')->values();
    }

    /**
     * Extract a metric's null-padded value series from a graph() result.
     *
     * @param  Collection<array-key, mixed>  $graph
     * @return list<int|null>
     */
    protected function series(Collection $graph, string $key, string $type): array
    {
        $byType = $graph->get($key);

        if (! $byType instanceof Collection) {
            return [];
        }

        $points = $byType->get($type);

        if (! $points instanceof Collection) {
            return [];
        }

        return array_values($points->map(fn ($v) => $v === null ? null : (int) $v)->all());
    }
}
