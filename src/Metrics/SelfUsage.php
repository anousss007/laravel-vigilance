<?php

namespace Vigilance\Metrics;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the monitoring itself costs.
 *
 * Vigilance sells itself on being "bounded by design" — sampling, size caps,
 * retention, a trim lottery. It could not, until now, tell you whether any of
 * that was working. This answers the two questions an operator actually has:
 * how much am I storing, and which telemetry type is responsible.
 *
 * Everything is rescued per table: a monitoring page must not 500 because one
 * optional feature's table was never migrated.
 */
class SelfUsage
{
    /**
     * Tables in write-volume order, with what turns each one off — the point of
     * the page is to get from "this is big" to "here is the knob".
     *
     * @var array<string, array{label: string, lever: string}>
     */
    protected const TABLES = [
        'vigilance_entries' => ['label' => 'APM entries (raw tail)', 'lever' => 'apm.recorders.*.sample_rate'],
        'vigilance_aggregates' => ['label' => 'APM rolled buckets', 'lever' => 'apm.storage.trim.keep'],
        'vigilance_spans' => ['label' => 'Trace spans', 'lever' => 'tracing.sample_rate'],
        'vigilance_traces' => ['label' => 'Traces', 'lever' => 'tracing.retention'],
        'vigilance_logs' => ['label' => 'Log explorer', 'lever' => 'logs.level / logs.sample_rate'],
        'vigilance_runs' => ['label' => 'Job & command runs', 'lever' => 'capture.sample_rate'],
        'vigilance_values' => ['label' => 'Latest-wins snapshots', 'lever' => '—'],
        'vigilance_metric_snapshots' => ['label' => 'Metric snapshots', 'lever' => 'metrics.retention'],
        'vigilance_failure_groups' => ['label' => 'Issues', 'lever' => 'issues.retention'],
        'vigilance_incidents' => ['label' => 'Incidents', 'lever' => '—'],
        'vigilance_audit' => ['label' => 'Control-plane audit', 'lever' => '—'],
        'vigilance_workers' => ['label' => 'Workers', 'lever' => '—'],
    ];

    /**
     * @return list<array{table: string, label: string, lever: string, rows: ?int, last_day: ?int, oldest: ?string}>
     */
    public function tables(): array
    {
        $rows = [];

        foreach (self::TABLES as $table => $meta) {
            $rows[] = [
                'table' => $table,
                'label' => $meta['label'],
                'lever' => $meta['lever'],
                'rows' => $this->count($table),
                'last_day' => $this->countSince($table, Carbon::now()->subDay()),
                'oldest' => $this->oldest($table),
            ];
        }

        usort($rows, fn ($a, $b) => ($b['rows'] ?? 0) <=> ($a['rows'] ?? 0));

        return $rows;
    }

    /**
     * Total rows across every Vigilance table, so the headline number is one
     * number rather than a table the reader has to add up.
     */
    public function totalRows(): int
    {
        return array_sum(array_map(fn ($row) => $row['rows'] ?? 0, $this->tables()));
    }

    /**
     * Whether pruning is actually keeping up: rows older than the configured
     * retention are rows the trim lottery has not got to.
     *
     * @return list<array{table: string, label: string, retention: string, stale: int}>
     */
    public function retentionBreaches(): array
    {
        $checks = [
            'vigilance_traces' => ['retention' => (string) config('vigilance.tracing.retention', '72 hours'), 'label' => 'Traces'],
            'vigilance_runs' => ['retention' => ((int) config('vigilance.retention_days', 7)).' days', 'label' => 'Job & command runs'],
            'vigilance_aggregates' => ['retention' => (string) config('vigilance.apm.storage.trim.keep', '7 days'), 'label' => 'APM rolled buckets'],
        ];

        $breaches = [];

        foreach ($checks as $table => $check) {
            $cutoff = $this->cutoffFor($check['retention']);

            if ($cutoff === null) {
                continue;
            }

            $stale = $this->countBefore($table, $cutoff);

            if ($stale !== null && $stale > 0) {
                $breaches[] = [
                    'table' => $table,
                    'label' => $check['label'],
                    'retention' => $check['retention'],
                    'stale' => $stale,
                ];
            }
        }

        return $breaches;
    }

    protected function count(string $table): ?int
    {
        return $this->rescue(fn () => (int) $this->connection()->table($table)->count());
    }

    protected function countSince(string $table, Carbon $since): ?int
    {
        return $this->rescue(function () use ($table, $since) {
            $column = $this->timeColumn($table);

            if ($column === null) {
                return null;
            }

            $value = $this->storesUnixSeconds($table) ? $since->getTimestamp() : $since;

            return (int) $this->connection()->table($table)->where($column, '>=', $value)->count();
        });
    }

    protected function countBefore(string $table, Carbon $cutoff): ?int
    {
        return $this->rescue(function () use ($table, $cutoff) {
            $column = $this->timeColumn($table);

            if ($column === null) {
                return null;
            }

            $value = $this->storesUnixSeconds($table) ? $cutoff->getTimestamp() : $cutoff;

            return (int) $this->connection()->table($table)->where($column, '<', $value)->count();
        });
    }

    protected function oldest(string $table): ?string
    {
        return $this->rescue(function () use ($table) {
            $column = $this->timeColumn($table);

            if ($column === null) {
                return null;
            }

            $value = $this->connection()->table($table)->min($column);

            if ($value === null) {
                return null;
            }

            $moment = $this->storesUnixSeconds($table)
                ? Carbon::createFromTimestamp((int) $value)
                : Carbon::parse((string) $value);

            return $moment->diffForHumans();
        });
    }

    /**
     * Vigilance's tables do not share one time column: the APM layer stores
     * unix seconds for cheapness, the rest use datetimes.
     */
    protected function timeColumn(string $table): ?string
    {
        return match ($table) {
            'vigilance_entries', 'vigilance_values' => 'timestamp',
            'vigilance_aggregates' => 'bucket',
            'vigilance_traces' => 'started_at',
            'vigilance_logs' => 'logged_at',
            'vigilance_incidents' => 'opened_at',
            'vigilance_metric_snapshots' => 'measured_at',
            'vigilance_runs', 'vigilance_failure_groups', 'vigilance_audit' => 'created_at',
            default => null,
        };
    }

    /**
     * The APM layer and tracing store unix seconds (cheaper to write and index
     * at that volume); everything else stores a datetime. Comparing the wrong
     * kind is how these queries silently return zero.
     */
    protected function storesUnixSeconds(string $table): bool
    {
        return in_array($table, [
            'vigilance_entries', 'vigilance_values', 'vigilance_aggregates', 'vigilance_traces',
        ], true);
    }

    protected function cutoffFor(string $retention): ?Carbon
    {
        try {
            return Carbon::parse('-'.ltrim($retention, '-'));
        } catch (Throwable) {
            return null;
        }
    }

    protected function connection(): Connection
    {
        return DB::connection(config('vigilance.storage.connection') ?: config('database.default'));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T|null
     */
    protected function rescue(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            // A table that was never migrated (an optional feature nobody
            // enabled) is a blank cell, not a 500 on the monitoring page.
            return null;
        }
    }
}
