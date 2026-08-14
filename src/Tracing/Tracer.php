<?php

namespace Vigilance\Tracing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;
use Throwable;
use Vigilance\Apm\Apm;
use Vigilance\Support\IncidentMode;
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

    /**
     * Upstream context (W3C traceparent) to fold into the next trace, set by
     * continueFrom() and consumed by start(). Null when this unit is a root.
     *
     * @var array{trace_id:string, span_id:string}|null
     */
    protected ?array $pendingParent = null;

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
        // Checked per call rather than trusting the constructor's snapshot:
        // incident mode can switch tracing on after this singleton was built,
        // and under Octane that singleton outlives many requests.
        if ($this->enabled) {
            return true;
        }

        return IncidentMode::active() && IncidentMode::tracingEnabled();
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
        if (! $this->enabled() || $this->current !== null) {
            $this->pendingParent = null;

            return;
        }

        $this->rescue(function () use ($type, $name, $start, $attributes) {
            // Distributed tracing: if an upstream context was handed in (an HTTP
            // traceparent header, or a dispatching request's context carried on
            // the job payload), record it so this trace links to its parent.
            if ($this->pendingParent !== null) {
                $attributes['parent_trace_id'] = $this->pendingParent['trace_id'];
                $attributes['parent_span_id'] = $this->pendingParent['span_id'];
            }

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

            $this->pendingParent = null;
        });
    }

    /**
     * Continue an upstream trace from a W3C traceparent
     * (00-{trace-id:32hex}-{span-id:16hex}-{flags:2hex}). The parent is folded
     * into the next start()ed trace's attributes. A null/invalid/all-zero value
     * is ignored (this unit becomes its own root). Returns whether it was taken.
     */
    public function continueFrom(?string $traceparent): bool
    {
        $this->pendingParent = null;

        if (! is_string($traceparent) || ! config('vigilance.tracing.propagation', true)) {
            return false;
        }

        if (! preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/i', trim($traceparent), $m)) {
            return false;
        }

        if (trim($m[1], '0') === '' || trim($m[2], '0') === '') {
            return false; // all-zero ids are invalid per the spec
        }

        $this->pendingParent = ['trace_id' => strtolower($m[1]), 'span_id' => strtolower($m[2])];

        return true;
    }

    /**
     * A W3C traceparent for the in-flight trace, to propagate downstream (into an
     * outgoing HTTP call or a dispatched job). Null when no trace is active. The
     * span-id is fresh per call so each downstream hop has a distinct parent span.
     */
    public function traceparent(): ?string
    {
        if ($this->current === null) {
            return null;
        }

        $traceId = substr(str_replace('-', '', (string) $this->current['id']).str_repeat('0', 32), 0, 32);
        $spanId = substr(bin2hex(random_bytes(8)), 0, 16);
        $flags = ! empty($this->current['sampled']) ? '01' : '00';

        return "00-{$traceId}-{$spanId}-{$flags}";
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

                // Promote N+1 to a first-class, aggregatable APM signal so it can
                // be counted and alerted on, not only spotted one trace at a time.
                // The key carries the route, the exact repeated SQL and the app
                // line, so the incident points straight at the offending query and
                // code — no digging through the trace.
                $key = (string) json_encode([
                    'name' => (string) $trace['name'],
                    'sql' => mb_substr((string) $nPlusOne['sql'], 0, 500),
                    'caller' => $nPlusOne['caller'] ?? null,
                ], JSON_UNESCAPED_SLASHES);

                $this->rescue(fn () => $this->app->make(Apm::class)
                    ->record('n_plus_one', $key, (int) $nPlusOne['count'])
                    ->count()->max());
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
        $this->pendingParent = null;
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
     * @return array{sql: string, count: int, caller?: ?string}|null
     */
    protected function detectNPlusOne(array $spans): ?array
    {
        $threshold = (int) $this->app->make('config')->get('vigilance.tracing.n_plus_one_threshold', 10);

        if ($threshold <= 0) {
            return null;
        }

        $counts = [];
        $callers = [];
        foreach ($spans as $span) {
            if (($span['type'] ?? null) === 'query') {
                $sql = (string) ($span['label'] ?? '');
                $counts[$sql] = ($counts[$sql] ?? 0) + 1;
                // Remember the app line the repeated query came from.
                $callers[$sql] ??= $span['attributes']['caller'] ?? null;
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

        return $max >= $threshold
            ? array_filter(['sql' => $sql, 'count' => $max, 'caller' => $callers[$sql] ?? null], fn ($v) => $v !== null)
            : null;
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
