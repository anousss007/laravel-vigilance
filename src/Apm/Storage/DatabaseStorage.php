<?php

namespace Vigilance\Apm\Storage;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Entry;
use Vigilance\Apm\Value;

/**
 * Driver-agnostic database storage for the APM layer — a faithful port of
 * Laravel Pulse's DatabaseStorage. Writes pre-aggregate entries into
 * time-bucketed rows; reads union the fresh "tail" (raw entries newer than the
 * newest complete bucket) with the rolled buckets so the current partial window
 * is accurate.
 *
 * @phpstan-type AggregateRow array{bucket: int, period: int, type: string, aggregate: string, key: string, value: int|float, count?: int, key_hash?: string}
 *
 * @internal
 */
class DatabaseStorage implements Storage
{
    /**
     * The allowed aggregate types.
     *
     * @var list<string>
     */
    protected array $allowedAggregates = ['count', 'min', 'max', 'sum', 'avg'];

    /**
     * Store the items.
     *
     * @param  Collection<int, Entry|Value>  $items
     */
    public function store(Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        [$entries, $values] = $items->partition(fn (Entry|Value $entry) => $entry instanceof Entry);

        /** @var Collection<int, Entry> $entries */
        /** @var Collection<int, Value> $values */
        $chunkSize = $this->chunkSize();
        $manualKeyHash = $this->requiresManualKeyHash();

        $entryChunks = $entries
            ->reject->isOnlyBuckets()
            ->map(function (Entry $entry) use ($manualKeyHash) {
                $attributes = $entry->attributes();

                return $manualKeyHash
                    ? [...$attributes, 'key_hash' => md5($attributes['key'])]
                    : $attributes;
            })
            ->chunk($chunkSize);

        [$counts, $minimums, $maximums, $sums, $averages] = array_values($entries
            ->reduce(function ($carry, Entry $entry) {
                foreach ($entry->aggregations as $aggregation) {
                    $carry[$aggregation][] = $entry;
                }

                return $carry;
            }, ['count' => [], 'min' => [], 'max' => [], 'sum' => [], 'avg' => []])
        );

        $countChunks = $this->preaggregateCounts(collect($counts))->chunk($chunkSize);
        $minimumChunks = $this->preaggregateMinimums(collect($minimums))->chunk($chunkSize);
        $maximumChunks = $this->preaggregateMaximums(collect($maximums))->chunk($chunkSize);
        $sumChunks = $this->preaggregateSums(collect($sums))->chunk($chunkSize);
        $averageChunks = $this->preaggregateAverages(collect($averages))->chunk($chunkSize);

        $valueChunks = $this
            ->collapseValues($values)
            ->map(function (Value $value) use ($manualKeyHash) {
                $attributes = $value->attributes();

                return $manualKeyHash
                    ? [...$attributes, 'key_hash' => md5($attributes['key'])]
                    : $attributes;
            })
            ->chunk($chunkSize);

        $this->connection()->transaction(function () use ($entryChunks, $countChunks, $minimumChunks, $maximumChunks, $sumChunks, $averageChunks, $valueChunks) {
            $entryChunks->each(fn ($chunk) => $this->connection()
                ->table('vigilance_entries')
                ->insert($chunk->all()));

            $countChunks->each(fn ($chunk) => $this->upsertCount($chunk->all()));
            $minimumChunks->each(fn ($chunk) => $this->upsertMin($chunk->all()));
            $maximumChunks->each(fn ($chunk) => $this->upsertMax($chunk->all()));
            $sumChunks->each(fn ($chunk) => $this->upsertSum($chunk->all()));
            $averageChunks->each(fn ($chunk) => $this->upsertAvg($chunk->all()));

            $valueChunks->each(fn ($chunk) => $this->connection()
                ->table('vigilance_values')
                ->upsert($chunk->all(), ['type', 'key_hash'], ['timestamp', 'value'])
            );
        }, 3);
    }

    /**
     * Trim the storage.
     */
    public function trim(): void
    {
        $now = CarbonImmutable::now();

        $keep = config('vigilance.apm.storage.trim.keep') ?? '7 days';

        $before = $now->subMilliseconds(
            (int) CarbonInterval::fromString($keep)->totalMilliseconds
        );

        if ($now->subDays(7)->isAfter($before)) {
            $before = $now->subDays(7);
        }

        $this->connection()
            ->table('vigilance_values')
            ->where('timestamp', '<=', $before->getTimestamp())
            ->delete();

        $this->connection()
            ->table('vigilance_entries')
            ->where('timestamp', '<=', $before->getTimestamp())
            ->delete();

        $this->connection()
            ->table('vigilance_aggregates')
            ->distinct()
            ->pluck('period')
            ->each(fn (int $period) => $this->connection()
                ->table('vigilance_aggregates')
                ->where('period', $period)
                ->where('bucket', '<=', max($now->subMinutes($period)->getTimestamp(), $before->getTimestamp()))
                ->delete());
    }

    /**
     * Purge the storage.
     *
     * @param  list<string>|null  $types
     */
    public function purge(?array $types = null): void
    {
        if ($types === null) {
            $this->connection()->table('vigilance_values')->truncate();
            $this->connection()->table('vigilance_entries')->truncate();
            $this->connection()->table('vigilance_aggregates')->truncate();

            return;
        }

        $this->connection()->table('vigilance_values')->whereIn('type', $types)->delete();
        $this->connection()->table('vigilance_entries')->whereIn('type', $types)->delete();
        $this->connection()->table('vigilance_aggregates')->whereIn('type', $types)->delete();
    }

    /**
     * Insert new records or update the existing ones and update the count.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertCount(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('vigilance_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($driver = $connection->getDriverName()) {
                    'mariadb', 'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "{$this->wrap('vigilance_aggregates.value')} + {$this->wrap('laravel_upsert_alias.value')}"
                            : '`value` + values(`value`)'
                    ),
                    'pgsql', 'sqlite' => new Expression(<<<SQL
                        {$this->wrap('vigilance_aggregates.value')} + "excluded"."value"
                        SQL),
                    default => throw new RuntimeException("Unsupported database driver [{$driver}]"),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the minimum.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertMin(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('vigilance_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($driver = $connection->getDriverName()) {
                    'mariadb', 'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "least({$this->wrap('vigilance_aggregates.value')}, {$this->wrap('laravel_upsert_alias.value')})"
                            : 'least(`value`, values(`value`))'
                    ),
                    'pgsql' => new Expression(<<<SQL
                        least({$this->wrap('vigilance_aggregates.value')}, "excluded"."value")
                        SQL),
                    'sqlite' => new Expression(<<<SQL
                        min({$this->wrap('vigilance_aggregates.value')}, "excluded"."value")
                        SQL),
                    default => throw new RuntimeException("Unsupported database driver [{$driver}]"),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the maximum.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertMax(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('vigilance_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($driver = $connection->getDriverName()) {
                    'mariadb', 'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "greatest({$this->wrap('vigilance_aggregates.value')}, {$this->wrap('laravel_upsert_alias.value')})"
                            : 'greatest(`value`, values(`value`))'
                    ),
                    'pgsql' => new Expression(<<<SQL
                        greatest({$this->wrap('vigilance_aggregates.value')}, "excluded"."value")
                        SQL),
                    'sqlite' => new Expression(<<<SQL
                        max({$this->wrap('vigilance_aggregates.value')}, "excluded"."value")
                        SQL),
                    default => throw new RuntimeException("Unsupported database driver [{$driver}]"),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the sum.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertSum(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('vigilance_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($driver = $connection->getDriverName()) {
                    'mariadb', 'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "{$this->wrap('vigilance_aggregates.value')} + {$this->wrap('laravel_upsert_alias.value')}"
                            : '`value` + values(`value`)'
                    ),
                    'pgsql', 'sqlite' => new Expression(<<<SQL
                        {$this->wrap('vigilance_aggregates.value')} + "excluded"."value"
                        SQL),
                    default => throw new RuntimeException("Unsupported database driver [{$driver}]"),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the average.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertAvg(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('vigilance_aggregates')->upsert(
            $values,
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            match ($driver = $connection->getDriverName()) {
                'mariadb', 'mysql' => $connection->getConfig('use_upsert_alias') ? [
                    'value' => new Expression(
                        "({$this->wrap('vigilance_aggregates.value')} * {$this->wrap('vigilance_aggregates.count')} + ({$this->wrap('laravel_upsert_alias.value')} * {$this->wrap('laravel_upsert_alias.count')})) / ({$this->wrap('vigilance_aggregates.count')} + {$this->wrap('laravel_upsert_alias.count')})"
                    ),
                    'count' => new Expression(
                        "{$this->wrap('vigilance_aggregates.count')} + {$this->wrap('laravel_upsert_alias.count')}"
                    ),
                ] : [
                    'value' => new Expression('(`value` * `count` + (values(`value`) * values(`count`))) / (`count` + values(`count`))'),
                    'count' => new Expression('`count` + values(`count`)'),
                ],
                'pgsql', 'sqlite' => [
                    'value' => new Expression(<<<SQL
                        ({$this->wrap('vigilance_aggregates.value')} * {$this->wrap('vigilance_aggregates.count')} + ("excluded"."value" * "excluded"."count")) / ({$this->wrap('vigilance_aggregates.count')} + "excluded"."count")
                        SQL),
                    'count' => new Expression(<<<SQL
                        {$this->wrap('vigilance_aggregates.count')} + "excluded"."count"
                        SQL),
                ],
                default => throw new RuntimeException("Unsupported database driver [{$driver}]"),
            }
        );
    }

    /**
     * Pre-aggregate entry counts.
     *
     * @param  Collection<int, Entry>  $entries
     * @return Collection<int, AggregateRow>
     */
    protected function preaggregateCounts(Collection $entries): Collection
    {
        return $this->preaggregate($entries, 'count', fn ($aggregate) => [
            ...$aggregate,
            'value' => ($aggregate['value'] ?? 0) + 1,
        ]);
    }

    /**
     * Pre-aggregate entry minimums.
     *
     * @param  Collection<int, Entry>  $entries
     * @return Collection<int, AggregateRow>
     */
    protected function preaggregateMinimums(Collection $entries): Collection
    {
        return $this->preaggregate($entries, 'min', fn ($aggregate, $entry) => [
            ...$aggregate,
            'value' => ! isset($aggregate['value'])
                ? $entry->value
                : (int) min($aggregate['value'], $entry->value),
        ]);
    }

    /**
     * Pre-aggregate entry maximums.
     *
     * @param  Collection<int, Entry>  $entries
     * @return Collection<int, AggregateRow>
     */
    protected function preaggregateMaximums(Collection $entries): Collection
    {
        return $this->preaggregate($entries, 'max', fn ($aggregate, $entry) => [
            ...$aggregate,
            'value' => ! isset($aggregate['value'])
                ? $entry->value
                : (int) max($aggregate['value'], $entry->value),
        ]);
    }

    /**
     * Pre-aggregate entry sums.
     *
     * @param  Collection<int, Entry>  $entries
     * @return Collection<int, AggregateRow>
     */
    protected function preaggregateSums(Collection $entries): Collection
    {
        return $this->preaggregate($entries, 'sum', fn ($aggregate, $entry) => [
            ...$aggregate,
            'value' => ($aggregate['value'] ?? 0) + $entry->value,
        ]);
    }

    /**
     * Pre-aggregate entry averages.
     *
     * @param  Collection<int, Entry>  $entries
     * @return Collection<int, AggregateRow>
     */
    protected function preaggregateAverages(Collection $entries): Collection
    {
        return $this->preaggregate($entries, 'avg', fn ($aggregate, $entry) => [
            ...$aggregate,
            'value' => ! isset($aggregate['value'])
                ? $entry->value
                : ($aggregate['value'] * $aggregate['count'] + $entry->value) / ($aggregate['count'] + 1),
            'count' => ($aggregate['count'] ?? 0) + 1,
        ]);
    }

    /**
     * Collapse the given values.
     *
     * @param  Collection<int, Value>  $values
     * @return Collection<int, Value>
     */
    protected function collapseValues(Collection $values): Collection
    {
        return $values->reverse()->unique(fn (Value $value) => [$value->key, $value->type]);
    }

    /**
     * Pre-aggregate entries with a callback.
     *
     * @param  Collection<int, Entry>  $entries
     * @return Collection<int, AggregateRow>
     */
    protected function preaggregate(Collection $entries, string $aggregate, Closure $callback): Collection
    {
        $aggregates = [];

        foreach ($entries as $entry) {
            foreach ($this->periods() as $period) {
                // Exclude entries that would be trimmed.
                if ($entry->timestamp < CarbonImmutable::now()->subMinutes($period)->getTimestamp()) {
                    continue;
                }

                $bucket = (int) (floor($entry->timestamp / $period) * $period);

                $key = $entry->type.':'.$period.':'.$bucket.':'.$entry->key;

                if (! isset($aggregates[$key])) {
                    $aggregates[$key] = $callback([
                        'bucket' => $bucket,
                        'period' => $period,
                        'type' => $entry->type,
                        'aggregate' => $aggregate,
                        'key' => $entry->key,
                    ], $entry);

                    if ($this->requiresManualKeyHash()) {
                        $aggregates[$key]['key_hash'] = md5($entry->key);
                    }
                } else {
                    $aggregates[$key] = $callback($aggregates[$key], $entry);
                }
            }
        }

        return collect(array_values($aggregates));
    }

    /**
     * The periods to aggregate for (in minutes).
     *
     * @return list<int>
     */
    protected function periods(): array
    {
        return [
            (int) (CarbonInterval::hour()->totalSeconds / 60),
            (int) (CarbonInterval::hours(6)->totalSeconds / 60),
            (int) (CarbonInterval::hours(24)->totalSeconds / 60),
            (int) (CarbonInterval::days(7)->totalSeconds / 60),
        ];
    }

    /**
     * Retrieve values for the given type.
     *
     * @param  list<string>|null  $keys
     * @return Collection<string, object>
     */
    public function values(string $type, ?array $keys = null): Collection
    {
        $rows = $this->connection()
            ->table('vigilance_values')
            ->select('timestamp', 'key', 'value')
            ->where('type', $type)
            ->when($keys, fn ($query) => $query->whereIn('key', $keys))
            ->get();

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row->key] = $row;
        }

        return $this->asObjectCollection($keyed);
    }

    /**
     * Retrieve aggregate values for plotting on a graph.
     *
     * 60-point time series per key: key => type => [datetime => value]. The
     * concrete nested return shape is inherited from the Storage contract.
     *
     * @param  list<string>  $types
     */
    public function graph(array $types, string $aggregate, CarbonInterval $interval): Collection
    {
        if (! in_array($aggregate, $this->allowedAggregates)) {
            throw new InvalidArgumentException("Invalid aggregate type [$aggregate], allowed types: [".implode(', ', $this->allowedAggregates).'].');
        }

        $now = CarbonImmutable::now();
        $period = $interval->totalSeconds / 60;
        $maxDataPoints = 60;
        $secondsPerPeriod = ($interval->totalSeconds / $maxDataPoints);
        $currentBucket = (int) (floor($now->getTimestamp() / $secondsPerPeriod) * $secondsPerPeriod);
        $firstBucket = $currentBucket - (($maxDataPoints - 1) * $secondsPerPeriod);

        // The 60 zero/null-padded datetime slots every series is filled against.
        $padding = [];

        for ($i = 0; $i <= 59; $i++) {
            $padding[CarbonImmutable::createFromTimestamp($firstBucket + $i * $secondsPerPeriod)->toDateTimeString()] = null;
        }

        $rows = $this->connection()->table('vigilance_aggregates')
            ->select(['bucket', 'type', 'key', 'value'])
            ->whereIn('type', $types)
            ->where('aggregate', $aggregate)
            ->where('period', $period)
            ->where('bucket', '>=', $firstBucket)
            ->orderBy('bucket')
            ->get();

        // Fold the readings into a key => type => [datetime => value] structure,
        // seeding every key/type slot with the padded series.
        /** @var array<string, array<string, array<string, int|null>>> $series */
        $series = [];

        foreach ($rows as $row) {
            $key = (string) $row->key;
            $type = (string) $row->type;

            if (! isset($series[$key])) {
                $series[$key] = [];

                foreach ($types as $t) {
                    $series[$key][(string) $t] = $padding;
                }
            }

            if (! isset($series[$key][$type])) {
                $series[$key][$type] = $padding;
            }

            $datetime = CarbonImmutable::createFromTimestamp((int) $row->bucket)->toDateTimeString();

            $series[$key][$type][$datetime] = $row->value === null ? null : (int) $row->value;
        }

        ksort($series);

        return $this->nest($series);
    }

    /**
     * Recursively wrap a nested array as nested collections (the leaf values are
     * left untouched). Returns a loosely-typed collection; callers narrow it to
     * the concrete nested shape they expect.
     *
     * @param  array<array-key, mixed>  $items
     * @return Collection<array-key, mixed>
     */
    protected function nest(array $items): Collection
    {
        return new Collection(array_map(
            fn (mixed $value): mixed => is_array($value) ? $this->nest($value) : $value,
            $items
        ));
    }

    /**
     * Retrieve aggregate values for the given type.
     *
     * Unions the live "tail" (raw entries newer than the newest complete bucket)
     * with the rolled buckets, groups by key_hash, recombines, and recovers the
     * key via a correlated subquery.
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
    ): Collection {
        $aggregates = is_array($aggregates) ? $aggregates : [$aggregates];

        if ($invalid = array_diff($aggregates, $this->allowedAggregates)) {
            throw new InvalidArgumentException('Invalid aggregate type(s) ['.implode(', ', $invalid).'], allowed types: ['.implode(', ', $this->allowedAggregates).'].');
        }

        $orderBy = in_array($orderBy, $aggregates, true) ? $orderBy : $aggregates[0];

        $rows = $this->connection()
            ->query()
            ->select([
                'key' => fn (Builder $query) => $query
                    ->select('key')
                    ->from('vigilance_entries', as: 'keys')
                    ->whereColumn('keys.key_hash', 'aggregated.key_hash')
                    ->limit(1),
                ...$aggregates,
            ])
            ->fromSub(function (Builder $query) use ($type, $aggregates, $interval, $orderBy, $direction, $limit) {
                $query->select('key_hash');

                foreach ($aggregates as $aggregate) {
                    $query->selectRaw(match ($aggregate) {
                        'count' => "sum({$this->wrap('count')})",
                        'min' => "min({$this->wrap('min')})",
                        'max' => "max({$this->wrap('max')})",
                        'sum' => "sum({$this->wrap('sum')})",
                        'avg' => "avg({$this->wrap('avg')})",
                        default => $this->invalidAggregate($aggregate),
                    }." as {$this->wrap($aggregate)}");
                }

                $query->fromSub(function (Builder $query) use ($type, $aggregates, $interval) {
                    $now = CarbonImmutable::now();
                    $period = $interval->totalSeconds / 60;
                    $windowStart = (int) ($now->getTimestamp() - $interval->totalSeconds + 1);
                    $currentBucket = (int) (floor($now->getTimestamp() / $period) * $period);
                    $oldestBucket = $currentBucket - $interval->totalSeconds + $period;

                    // Tail
                    $query->select('key_hash');

                    foreach ($aggregates as $aggregate) {
                        $query->selectRaw(match ($aggregate) {
                            'count' => 'count(*)',
                            'min' => "min({$this->wrap('value')})",
                            'max' => "max({$this->wrap('value')})",
                            'sum' => "sum({$this->wrap('value')})",
                            'avg' => "avg({$this->wrap('value')})",
                            default => $this->invalidAggregate($aggregate),
                        }." as {$this->wrap($aggregate)}");
                    }

                    $query
                        ->from('vigilance_entries')
                        ->where('type', $type)
                        ->where('timestamp', '>=', $windowStart)
                        ->where('timestamp', '<=', $oldestBucket - 1)
                        ->groupBy('key_hash');

                    // Buckets
                    foreach ($aggregates as $currentAggregate) {
                        $query->unionAll(function (Builder $query) use ($type, $aggregates, $currentAggregate, $period, $oldestBucket) {
                            $query->select('key_hash');

                            foreach ($aggregates as $aggregate) {
                                if ($aggregate === $currentAggregate) {
                                    $query->selectRaw(match ($aggregate) {
                                        'count' => "sum({$this->wrap('value')})",
                                        'min' => "min({$this->wrap('value')})",
                                        'max' => "max({$this->wrap('value')})",
                                        'sum' => "sum({$this->wrap('value')})",
                                        'avg' => "avg({$this->wrap('value')})",
                                        default => $this->invalidAggregate($aggregate),
                                    }." as {$this->wrap($aggregate)}");
                                } else {
                                    $query->selectRaw("null as {$this->wrap($aggregate)}");
                                }
                            }

                            $query
                                ->from('vigilance_aggregates')
                                ->where('period', $period)
                                ->where('type', $type)
                                ->where('aggregate', $currentAggregate)
                                ->where('bucket', '>=', $oldestBucket)
                                ->groupBy('key_hash');
                        });
                    }
                }, as: 'results')
                    ->groupBy('key_hash')
                    ->orderBy($orderBy, $direction)
                    ->limit($limit);
            }, as: 'aggregated')
            ->get()
            ->all();

        return $this->asObjectCollection($rows);
    }

    /**
     * Retrieve an aggregate total for the given types.
     *
     * Scalar total across the interval (tail + buckets).
     *
     * @param  string|list<string>  $types
     */
    public function aggregateTotal(
        string|array $types,
        string $aggregate,
        CarbonInterval $interval,
    ): float {
        if (! in_array($aggregate, $this->allowedAggregates)) {
            throw new InvalidArgumentException("Invalid aggregate type [$aggregate], allowed types: [".implode(', ', $this->allowedAggregates).'].');
        }

        $now = CarbonImmutable::now();
        $period = $interval->totalSeconds / 60;
        $windowStart = (int) ($now->getTimestamp() - $interval->totalSeconds + 1);
        $currentBucket = (int) (floor($now->getTimestamp() / $period) * $period);
        $oldestBucket = $currentBucket - $interval->totalSeconds + $period;
        $tailStart = $windowStart;
        $tailEnd = $oldestBucket - 1;

        return (float) $this->connection()->query()
            ->selectRaw(match ($aggregate) {
                'count' => "sum({$this->wrap('count')})",
                'min' => "min({$this->wrap('min')})",
                'max' => "max({$this->wrap('max')})",
                'sum' => "sum({$this->wrap('sum')})",
                'avg' => "avg({$this->wrap('avg')})",
                default => $this->invalidAggregate($aggregate),
            }." as {$this->wrap($aggregate)}")
            ->fromSub(fn (Builder $query) => $query
                // Tail
                ->addSelect('type')
                ->selectRaw(match ($aggregate) {
                    'count' => 'count(*)',
                    'min' => "min({$this->wrap('value')})",
                    'max' => "max({$this->wrap('value')})",
                    'sum' => "sum({$this->wrap('value')})",
                    'avg' => "avg({$this->wrap('value')})",
                }." as {$this->wrap($aggregate)}")
                ->from('vigilance_entries')
                ->when(
                    is_array($types),
                    fn ($query) => $query->whereIn('type', $types),
                    fn ($query) => $query->where('type', $types)
                )
                ->where('timestamp', '>=', $tailStart)
                ->where('timestamp', '<=', $tailEnd)
                ->groupBy('type')
                // Buckets
                ->unionAll(fn (Builder $query) => $query
                    ->select('type')
                    ->selectRaw(match ($aggregate) {
                        'count' => "sum({$this->wrap('value')})",
                        'min' => "min({$this->wrap('value')})",
                        'max' => "max({$this->wrap('value')})",
                        'sum' => "sum({$this->wrap('value')})",
                        'avg' => "avg({$this->wrap('value')})",
                    }." as {$this->wrap($aggregate)}")
                    ->from('vigilance_aggregates')
                    ->where('period', $period)
                    ->when(
                        is_array($types),
                        fn ($query) => $query->whereIn('type', $types),
                        fn ($query) => $query->where('type', $types)
                    )
                    ->where('aggregate', $aggregate)
                    ->where('bucket', '>=', $oldestBucket)
                    ->groupBy('type')
                ), as: 'child'
            )
            ->value($aggregate);
    }

    /**
     * The number of rows to write per chunk.
     */
    protected function chunkSize(): int
    {
        return (int) (config('vigilance.apm.storage.chunk') ?? 1000);
    }

    /**
     * Wrap rows as an opaque object collection, matching the storage contract
     * (callers treat each row as a read-only object).
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, object>  $rows
     * @return Collection<TKey, object>
     */
    protected function asObjectCollection(array $rows): Collection
    {
        return new Collection($rows);
    }

    /**
     * Bail out on an unsupported aggregate type.
     *
     * @return never
     */
    protected function invalidAggregate(string $aggregate): string
    {
        throw new InvalidArgumentException("Invalid aggregate type [$aggregate], allowed types: [".implode(', ', $this->allowedAggregates).'].');
    }

    /**
     * Resolve the database connection.
     */
    protected function connection(): Connection
    {
        return DB::connection(
            config('vigilance.storage.connection') ?: config('database.default')
        );
    }

    /**
     * Wrap a value in keyword identifiers.
     */
    protected function wrap(string $value): string
    {
        return $this->connection()->getQueryGrammar()->wrap($value);
    }

    /**
     * Determine whether a manually generated key hash is required.
     */
    protected function requiresManualKeyHash(): bool
    {
        return $this->connection()->getDriverName() === 'sqlite';
    }
}
