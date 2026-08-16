<?php

namespace Vigilance\Metrics;

use Carbon\CarbonInterval;
use Cron\CronExpression;
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
     * Whether pruning is actually keeping up.
     *
     * Retention is enforced by a periodic job, not continuously, so at any
     * moment the oldest rows are legitimately up to one prune interval past
     * their window. Counting those as a breach makes the check fire for ever on
     * every install that follows the package's own advice to prune daily —
     * traces are kept 72h, so a daily prune leaves up to 24h of overhang by
     * design. The grace below is what separates "behind" from "between runs".
     *
     * @return list<array{table: string, label: string, retention: string, grace: string, stale: int}>
     */
    public function retentionBreaches(): array
    {
        $checks = [
            'vigilance_traces' => ['retention' => (string) config('vigilance.tracing.retention', '72 hours'), 'label' => 'Traces'],
            'vigilance_runs' => ['retention' => ((int) config('vigilance.retention.days', 14)).' days', 'label' => 'Job & command runs'],
            'vigilance_aggregates' => ['retention' => (string) config('vigilance.apm.storage.trim.keep', '7 days'), 'label' => 'APM rolled buckets'],
            'vigilance_logs' => ['retention' => (string) config('vigilance.logs.retention', '72 hours'), 'label' => 'Log explorer'],
        ];

        $grace = $this->pruneInterval();
        $breaches = [];

        foreach ($checks as $table => $check) {
            $cutoff = $this->cutoffFor($check['retention']);

            if ($cutoff === null) {
                continue;
            }

            $stale = $this->countBefore($table, $cutoff->copy()->subSeconds($grace));

            if ($stale !== null && $stale > 0) {
                $breaches[] = [
                    'table' => $table,
                    'label' => $check['label'],
                    'retention' => $check['retention'],
                    'grace' => CarbonInterval::seconds($grace)->cascade()->forHumans(['short' => true]),
                    'stale' => $stale,
                ];
            }
        }

        return $breaches;
    }

    /**
     * How long retention is allowed to overhang before it counts as a breach:
     * one prune interval, read from the synced schedule so the check matches
     * whatever cadence this install actually runs.
     *
     * Falls back to a day — the cadence `vigilance:install` and the README
     * recommend — when the schedule has never been synced.
     */
    public function pruneInterval(): int
    {
        $default = (int) CarbonInterval::day()->totalSeconds;

        $expression = $this->rescue(fn () => $this->connection()
            ->table('vigilance_scheduled_tasks')
            ->where('name', 'like', 'vigilance:prune%')
            ->orderBy('name')
            ->value('cron_expression'));

        if (! is_string($expression) || trim($expression) === '') {
            return $default;
        }

        try {
            $cron = new CronExpression($expression);

            // The widest gap in the cycle, not merely the next one: a schedule
            // like "0 9,17 * * *" alternates 8h and 16h, and grading the 16h
            // stretch against the 8h gap would resurrect the false alarm this
            // grace exists to prevent.
            $seconds = 0;
            $from = $cron->getNextRunDate('now', 0, true);

            for ($i = 0; $i < 6; $i++) {
                $next = $cron->getNextRunDate($from, 0, false);
                $seconds = max($seconds, $next->getTimestamp() - $from->getTimestamp());
                $from = $next;
            }
        } catch (Throwable) {
            return $default;
        }

        // A cadence we cannot make sense of must not silence the check.
        return $seconds > 0 ? $seconds : $default;
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
     * The column each table is dated by, and whether it stores unix seconds.
     *
     * Kept as one map rather than two methods on purpose: the APM and tracing
     * tables store unix seconds (cheaper to write and index at that volume)
     * while the rest store datetimes, and holding the column and its kind apart
     * is how `logged_at` ended up compared against a Carbon instance. SQLite is
     * loosely typed and accepted it; PostgreSQL rejected `integer >= varchar`,
     * and — being PostgreSQL — aborted the surrounding transaction, so every
     * later query on the page came back null too.
     *
     * SelfUsageColumnsTest checks this map against the real schema, on every
     * database CI runs.
     *
     * @return array<string, array{column: string, unix: bool}>
     */
    public static function timeColumns(): array
    {
        return [
            'vigilance_entries' => ['column' => 'timestamp', 'unix' => true],
            'vigilance_values' => ['column' => 'timestamp', 'unix' => true],
            'vigilance_aggregates' => ['column' => 'bucket', 'unix' => true],
            'vigilance_traces' => ['column' => 'started_at', 'unix' => true],
            'vigilance_logs' => ['column' => 'logged_at', 'unix' => true],
            'vigilance_incidents' => ['column' => 'opened_at', 'unix' => false],
            'vigilance_metric_snapshots' => ['column' => 'measured_at', 'unix' => false],
            'vigilance_runs' => ['column' => 'created_at', 'unix' => false],
            'vigilance_failure_groups' => ['column' => 'created_at', 'unix' => false],
            'vigilance_audit' => ['column' => 'created_at', 'unix' => false],
        ];
    }

    protected function timeColumn(string $table): ?string
    {
        return self::timeColumns()[$table]['column'] ?? null;
    }

    protected function storesUnixSeconds(string $table): bool
    {
        return self::timeColumns()[$table]['unix'] ?? false;
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
