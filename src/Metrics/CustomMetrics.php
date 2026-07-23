<?php

namespace Vigilance\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        $distributions = $this->storage->aggregate('metric_distribution', ['avg', 'max', 'count'], $interval, orderBy: 'count', limit: 100);
        $distGraph = $this->storage->graph(['metric_distribution'], 'avg', $interval);
        $percentiles = $this->percentiles($interval, $distributions->map(fn ($r) => (string) $r->key)->all());

        foreach ($distributions as $row) {
            $p = $percentiles[(string) $row->key] ?? ['p50' => null, 'p95' => null, 'p99' => null];

            $out->push(new CustomMetricStat(
                name: (string) $row->key,
                type: 'distribution',
                value: (int) round((float) $row->avg),
                peak: (int) $row->max,
                series: $this->series($distGraph, (string) $row->key, 'metric_distribution'),
                p50: $p['p50'],
                p95: $p['p95'],
                p99: $p['p99'],
            ));
        }

        return $out->sortBy('name')->values();
    }

    /**
     * Exact p50/p95/p99 per distribution key, from the retained raw samples
     * within the window (mirrors RoutePerformance's request-latency percentiles).
     *
     * @param  list<string>  $keys
     * @return array<string, array{p50:?int, p95:?int, p99:?int}>
     */
    protected function percentiles(CarbonInterval $interval, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $windowStart = CarbonImmutable::now()->getTimestamp() - (int) $interval->totalSeconds + 1;

        $rows = DB::connection(config('vigilance.storage.connection') ?: config('database.default'))
            ->table('vigilance_entries')
            ->select('key', 'value')
            ->where('type', 'metric_distribution')
            ->whereIn('key', $keys)
            ->where('timestamp', '>=', $windowStart)
            ->whereNotNull('value')
            ->get();

        /** @var array<string, list<int>> $byKey */
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string) $row->key][] = (int) $row->value;
        }

        $out = [];
        foreach ($byKey as $key => $values) {
            sort($values);
            $out[$key] = [
                'p50' => $this->quantile($values, 0.50),
                'p95' => $this->quantile($values, 0.95),
                'p99' => $this->quantile($values, 0.99),
            ];
        }

        return $out;
    }

    /**
     * Nearest-rank quantile over a pre-sorted list.
     *
     * @param  list<int>  $sorted
     */
    protected function quantile(array $sorted, float $q): ?int
    {
        $n = count($sorted);

        if ($n === 0) {
            return null;
        }

        $rank = (int) ceil($q * $n) - 1;

        return $sorted[max(0, min($n - 1, $rank))];
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
