<?php

namespace Vigilance\Tracing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;
use Throwable;
use Vigilance\Tracing\Contracts\TraceStorage;
use Vigilance\Tracing\Sampling\Sampler;

/**
 * The request-scoped tracer. A trace is collected in a cheap in-memory buffer
 * during the request — recording a span is a bool check plus an array push, no
 * I/O — and persisted only when the trace turns out to be worth keeping
 * (head-sampled, slow, or errored). Everything else is discarded, which is what
 * keeps tracing affordable under heavy traffic.
 *
 * Persistence happens on finish(), which for HTTP is wired to the terminate
 * phase (after the response is sent), so tracing never adds request latency.
 * Every path is rescued so monitoring can never break the host app.
 */
class Tracer
{
    /**
     * The in-flight trace, or null when nothing is being traced. Kept as a plain
     * array (not an object graph) to minimise allocation on the hot path.
     *
     * @var array{id:string,type:string,name:string,start:float,sampled:bool,attributes:array<string,mixed>,spans:list<array<string,mixed>>,dropped:int}|null
     */
    protected ?array $current = null;

    protected bool $enabled;

    protected int $maxSpans;

    protected int $slowThreshold;

    protected int $maxAttributeLength;

    protected ?Closure $handleExceptionsUsing = null;

    public function __construct(
        protected Container $app,
        protected Sampler $sampler,
    ) {
        $config = $app->make('config');
        $this->enabled = (bool) $config->get('vigilance.tracing.enabled', false);
        $this->maxSpans = (int) $config->get('vigilance.tracing.max_spans', 1000);
        $this->slowThreshold = (int) $config->get('vigilance.tracing.slow_threshold', 1000);
        $this->maxAttributeLength = (int) $config->get('vigilance.tracing.max_attribute_length', 2000);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether a trace is currently collecting — the cheap gate instrumentation
     * checks before doing any span work.
     */
    public function sampling(): bool
    {
        return $this->current !== null;
    }

    public function currentTraceId(): ?string
    {
        return $this->current['id'] ?? null;
    }

    /**
     * Begin a trace. A unit (request/job/command) that starts a trace while one
     * is already open is ignored — the outer trace wins.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function start(string $type, string $name, ?float $start = null, array $attributes = []): void
    {
        if (! $this->enabled || $this->current !== null) {
            return;
        }

        $this->rescue(function () use ($type, $name, $start, $attributes) {
            $this->current = [
                'id' => (string) Str::orderedUuid(),
                'type' => $type,
                'name' => $name,
                'start' => $start ?? microtime(true),
                'sampled' => $this->sampler->shouldSample($type),
                'attributes' => $attributes,
                'spans' => [],
                'dropped' => 0,
            ];
        });
    }

    /**
     * Record a completed child span. Cheap and bounded: once the per-trace cap
     * is hit, further spans only bump the "dropped" counter.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function span(string $type, string $label, float $startedAt, float $endedAt, array $attributes = []): void
    {
        if ($this->current === null) {
            return;
        }

        if (count($this->current['spans']) >= $this->maxSpans) {
            $this->current['dropped']++;

            return;
        }

        $offsetUs = (int) max(0, round(($startedAt - $this->current['start']) * 1_000_000));
        $durationUs = (int) max(0, round(($endedAt - $startedAt) * 1_000_000));

        $this->current['spans'][] = [
            'type' => $type,
            'label' => $this->truncate($label),
            'offset' => $offsetUs,
            'duration' => $durationUs,
            'attributes' => $attributes,
        ];
    }

    /**
     * Convenience for event-driven spans that only report a duration (ms) and
     * are taken to have just ended.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function spanForDuration(string $type, string $label, float $durationMs, array $attributes = []): void
    {
        if ($this->current === null) {
            return;
        }

        $end = microtime(true);

        $this->span($type, $label, $end - ($durationMs / 1000), $end, $attributes);
    }

    /**
     * Merge attributes into the in-flight trace (e.g. the HTTP status once the
     * response is known).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function setAttributes(array $attributes): void
    {
        if ($this->current !== null) {
            $this->current['attributes'] = [...$this->current['attributes'], ...$attributes];
        }
    }

    /**
     * Finalise the in-flight trace. It is persisted only when head-sampled,
     * slow, or errored — otherwise dropped. Always clears the current trace.
     */
    public function finish(string $status = 'ok', ?float $end = null): void
    {
        $trace = $this->current;
        $this->current = null;

        if ($trace === null) {
            return;
        }

        $this->rescue(function () use ($trace, $status, $end) {
            $durationMs = (int) round((($end ?? microtime(true)) - $trace['start']) * 1000);

            $keep = $trace['sampled']
                || $status === 'error'
                || $durationMs >= $this->slowThreshold;

            if (! $keep) {
                return;
            }

            $attributes = $trace['attributes'];

            if ($nPlusOne = $this->detectNPlusOne($trace['spans'])) {
                $attributes['n_plus_one'] = $nPlusOne;
            }

            $this->app->make(TraceStorage::class)->store([
                'id' => $trace['id'],
                'type' => $trace['type'],
                'name' => $trace['name'],
                'status' => $status,
                'duration_ms' => $durationMs,
                'span_count' => count($trace['spans']),
                'dropped_spans' => $trace['dropped'],
                'user_id' => $this->authenticatedUserId(),
                'started_at' => (int) $trace['start'],
                'attributes' => $attributes,
                'spans' => $trace['spans'],
            ]);

            Lottery::odds(...$this->trimLotteryOdds())
                ->winner(fn () => $this->app->make(TraceStorage::class)->trim())
                ->choose();
        });
    }

    /**
     * Drop the in-flight trace without persisting (used on Octane request reset).
     */
    public function flush(): void
    {
        $this->current = null;
    }

    public function setContainer(Container $container): void
    {
        $this->app = $container;
    }

    public function container(): Container
    {
        return $this->app;
    }

    public function handleExceptionsUsing(Closure $callback): void
    {
        $this->handleExceptionsUsing = $callback;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    public function rescue(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            ($this->handleExceptionsUsing ?? fn () => null)($e);

            return null;
        }
    }

    protected function authenticatedUserId(): int|string|null
    {
        return $this->rescue(fn () => $this->app->make('auth')->guard()->id());
    }

    /**
     * Detect a likely N+1: the same query shape (identical parameterised SQL)
     * repeated at least the configured number of times in one trace.
     *
     * @param  list<array<string, mixed>>  $spans
     * @return array{sql: string, count: int}|null
     */
    protected function detectNPlusOne(array $spans): ?array
    {
        $threshold = (int) $this->app->make('config')->get('vigilance.tracing.n_plus_one_threshold', 10);

        if ($threshold <= 0) {
            return null;
        }

        $counts = [];
        foreach ($spans as $span) {
            if (($span['type'] ?? null) === 'query') {
                $sql = (string) ($span['label'] ?? '');
                $counts[$sql] = ($counts[$sql] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        $sql = (string) array_key_first($counts);
        $max = $counts[$sql];
        foreach ($counts as $candidate => $count) {
            if ($count > $max) {
                $max = $count;
                $sql = (string) $candidate;
            }
        }

        return $max >= $threshold ? ['sql' => $sql, 'count' => $max] : null;
    }

    protected function truncate(string $value): string
    {
        return $this->maxAttributeLength > 0 ? mb_substr($value, 0, $this->maxAttributeLength) : $value;
    }

    /** @return array{0:int,1:int} */
    protected function trimLotteryOdds(): array
    {
        $odds = (array) $this->app->make('config')->get('vigilance.tracing.trim.lottery', [1, 200]);

        return [(int) ($odds[0] ?? 1), (int) ($odds[1] ?? 200)];
    }
}
