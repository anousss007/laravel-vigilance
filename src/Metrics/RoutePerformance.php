<?php

namespace Vigilance\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Vigilance\Apm\Contracts\Storage;

/**
 * Per-route HTTP performance for the Routes page: throughput, error rate, Apdex,
 * average/max latency and exact p50/p95/p99 — the latter computed from the raw
 * per-request durations the Requests recorder stores as entry values, for the
 * routes actually shown (top by throughput), so the percentile query stays
 * bounded.
 */
class RoutePerformance
{
    public function __construct(protected Storage $storage) {}

    /**
     * @return Collection<int, RouteStat>
     */
    public function forInterval(CarbonInterval $interval, int $limit = 50): Collection
    {
        $base = $this->storage->aggregate('request', ['count', 'avg', 'max'], $interval, orderBy: 'count', limit: $limit);

        if ($base->isEmpty()) {
            return new Collection;
        }

        $keys = $base->map(fn ($row) => (string) $row->key)->all();

        $apdex = $this->storage->aggregate('request_apdex', ['avg'], $interval, limit: $limit * 2)->keyBy('key');
        $errors = $this->storage->aggregate('request_error', ['count'], $interval, limit: $limit * 2)->keyBy('key');
        $percentiles = $this->percentiles($interval, $keys);

        return $base->map(function ($row) use ($apdex, $errors, $percentiles): RouteStat {
            $decoded = json_decode((string) $row->key, true);
            $method = is_array($decoded) ? (string) ($decoded[0] ?? '') : '';
            $path = is_array($decoded) ? (string) ($decoded[1] ?? $row->key) : (string) $row->key;

            $count = (int) $row->count;
            $errorCount = isset($errors[$row->key]) ? (int) $errors[$row->key]->count : 0;
            $p = $percentiles[(string) $row->key] ?? ['p50' => null, 'p95' => null, 'p99' => null];

            return new RouteStat(
                method: $method,
                path: $path,
                count: $count,
                errors: $errorCount,
                error_rate: $count > 0 ? round($errorCount / $count * 100, 2) : 0.0,
                apdex: isset($apdex[$row->key]) ? round(((float) $apdex[$row->key]->avg) / 100, 3) : null,
                avg: (int) round((float) $row->avg),
                max: (int) $row->max,
                p50: $p['p50'],
                p95: $p['p95'],
                p99: $p['p99'],
            );
        })->values();
    }

    /**
     * Exact percentiles per route key, computed from the retained raw request
     * durations within the window (bounded to the given keys).
     *
     * @param  list<string>  $keys
     * @return array<string, array{p50: ?int, p95: ?int, p99: ?int}>
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
            ->where('type', 'request')
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
     * Nearest-rank percentile of a pre-sorted, non-empty list.
     *
     * @param  list<int>  $sorted
     */
    protected function quantile(array $sorted, float $q): int
    {
        $n = count($sorted);

        if ($n === 0) {
            return 0;
        }

        $rank = (int) ceil($q * $n) - 1;
        $rank = max(0, min($n - 1, $rank));

        return $sorted[$rank];
    }
}
