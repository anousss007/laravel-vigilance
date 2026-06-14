<?php

namespace Vigilance\Apm;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Lottery;
use Throwable;
use Vigilance\Apm\Contracts\Ingest;

/**
 * The APM coordinator (a container singleton). Recorders push entries/values
 * into an in-memory buffer during the request; nothing heavy runs on the hot
 * path. The buffer is flushed in the terminate phase (after the response is
 * sent) via the configured Ingest. Every telemetry path is wrapped so it can
 * never break the host application.
 *
 * Mirrors Laravel Pulse's prod-safety model, driver-agnostic.
 */
class Apm
{
    /** @var Collection<int, Entry|Value> */
    protected Collection $entries;

    /** @var Collection<int, Closure> */
    protected Collection $lazy;

    /** @var list<Closure> */
    protected array $filters = [];

    /** @var list<object> */
    protected array $recorders = [];

    protected bool $shouldRecord = true;

    protected bool $evaluatingBuffer = false;

    protected bool $registered = false;

    protected ?Closure $handleExceptionsUsing = null;

    protected ?Closure $authenticatedUserIdResolver = null;

    protected ?Closure $userResolver = null;

    protected int|string|null $rememberedUserId = null;

    public function __construct(protected Container $app)
    {
        $this->entries = new Collection;
        $this->lazy = new Collection;
    }

    /**
     * Record a metric. Returns the Entry so aggregations can be chained
     * (->count()->max()…). The entry is buffered, not written yet.
     */
    public function record(string $type, string $key, ?int $value = null, ?int $timestamp = null): Entry
    {
        $timestamp ??= time();

        $entry = new Entry($timestamp, $type, $key, $value);

        if ($this->shouldRecord) {
            $this->entries->push($entry);
            $this->ingestWhenOverBufferSize();
        }

        return $entry;
    }

    /**
     * Record a latest-wins snapshot value (upserted by type+key).
     */
    public function set(string $type, string $key, string $value, ?int $timestamp = null): void
    {
        if (! $this->shouldRecord) {
            return;
        }

        $this->entries->push(new Value($timestamp ?? time(), $type, $key, $value));
        $this->ingestWhenOverBufferSize();
    }

    /**
     * Defer expensive capture work (sampling, regex, backtraces, normalization)
     * until flush time, so the request thread pays almost nothing.
     */
    public function lazy(Closure $closure): static
    {
        if ($this->shouldRecord) {
            $this->lazy->push($closure);
            $this->ingestWhenOverBufferSize();
        }

        return $this;
    }

    /**
     * Run a callback with recording suppressed (prevents the recorder from
     * observing its own DB writes during ingest/reads).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function ignore(Closure $callback): mixed
    {
        $previous = $this->shouldRecord;
        $this->shouldRecord = false;

        try {
            return $callback();
        } finally {
            $this->shouldRecord = $previous;
        }
    }

    /**
     * Register a filter predicate; an entry is only ingested if every filter
     * returns true.
     *
     * @param  Closure(Entry|Value): bool  $filter
     */
    public function filter(Closure $filter): static
    {
        $this->filters[] = $filter;

        return $this;
    }

    /**
     * Flush the buffer through the ingest driver. Wired to fire in the terminate
     * phase — after the response is sent — so it never adds request latency.
     */
    public function ingest(): int
    {
        return $this->rescue(function () {
            $this->resolveLazyEntries();

            return $this->ignore(function () {
                $entries = $this->entries->filter(fn (Entry|Value $entry) => $this->shouldRecordEntry($entry))->values();

                if ($entries->isNotEmpty()) {
                    $ingest = $this->app->make(Ingest::class);
                    $ingest->ingest($entries);

                    Lottery::odds(...$this->trimLotteryOdds())->winner(fn () => $ingest->trim())->choose();
                }

                $count = $entries->count();

                $this->flush();

                return $count;
            });
        }) ?? 0;
    }

    public function flush(): static
    {
        $this->entries = new Collection;
        $this->lazy = new Collection;
        $this->rememberedUserId = null;

        return $this;
    }

    public function startRecording(): void
    {
        $this->shouldRecord = true;
    }

    public function stopRecording(): void
    {
        $this->shouldRecord = false;
    }

    /**
     * Wire the configured recorders to their framework events / custom hooks.
     *
     * @param  array<class-string|int, mixed>  $recorders
     */
    public function register(array $recorders): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        $events = $this->app->make('events');

        foreach ($recorders as $class => $config) {
            if (is_int($class)) {
                $class = $config;
            }

            if (is_array($config) && ($config['enabled'] ?? true) === false) {
                continue;
            }

            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $recorder = $this->app->make($class);
            $this->recorders[] = $recorder;

            foreach ((array) ($recorder->listen ?? []) as $event) {
                $events->listen($event, fn ($e) => $this->rescue(fn () => $recorder->record($e)));
            }

            if (method_exists($recorder, 'register')) {
                $this->rescue(fn () => $recorder->register($this));
            }
        }
    }

    /**
     * Run a telemetry callback, swallowing any error so monitoring can never
     * break the host app.
     *
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

    public function handleExceptionsUsing(Closure $callback): void
    {
        $this->handleExceptionsUsing = $callback;
    }

    /**
     * Re-bind the container (Octane sandbox swap) so the singleton resolves
     * dependencies against the current request, not the stale boot-time one.
     */
    public function setContainer(Container $container): void
    {
        $this->app = $container;
    }

    /**
     * The container the coordinator is currently bound to (Octane-aware). Used
     * by recorders that wire framework hooks at registration time.
     */
    public function container(): Container
    {
        return $this->app;
    }

    /**
     * @param  Closure(): (int|string|null)  $resolver
     */
    public function resolveAuthenticatedUserIdUsing(Closure $resolver): void
    {
        $this->authenticatedUserIdResolver = $resolver;
    }

    public function authenticatedUserId(): int|string|null
    {
        return $this->rememberedUserId ??= $this->rescue(
            $this->authenticatedUserIdResolver ?? fn () => $this->app->make('auth')->guard()->id(),
        );
    }

    public function rememberUser(int|string|null $id): void
    {
        $this->rememberedUserId = $id;
    }

    /**
     * Customise how the current authenticated user is shaped for the
     * Application Usage card.
     *
     * @param  Closure(): (array{id: int|string, name: string, extra?: string}|null)  $resolver
     */
    public function resolveUserUsing(Closure $resolver): void
    {
        $this->userResolver = $resolver;
    }

    /**
     * The current authenticated user as a display row, or null when nobody is
     * authenticated. Used by the per-user usage recorders.
     *
     * @return array{id: int|string, name: string, extra?: string}|null
     */
    public function resolveUser(): ?array
    {
        return $this->rescue(function () {
            if ($this->userResolver !== null) {
                return ($this->userResolver)();
            }

            $user = $this->app->make('auth')->guard()->user();

            if ($user === null) {
                return null;
            }

            $id = $user->getAuthIdentifier();

            if ($id === null || (! is_int($id) && ! is_string($id))) {
                return null;
            }

            $name = data_get($user, 'name');
            $email = data_get($user, 'email');

            return [
                'id' => $id,
                'name' => (string) ($name ?? $email ?? ('User #'.$id)),
                'extra' => is_string($email) ? $email : '',
            ];
        });
    }

    protected function resolveLazyEntries(): void
    {
        $this->ignore(function () {
            while ($this->lazy->isNotEmpty()) {
                $lazy = $this->lazy;
                $this->lazy = new Collection;

                // Lazy closures call record()/set(), which (re)populate $entries.
                $previous = $this->shouldRecord;
                $this->shouldRecord = true;
                $lazy->each(fn (Closure $closure) => $this->rescue($closure));
                $this->shouldRecord = $previous;
            }
        });
    }

    protected function shouldRecordEntry(Entry|Value $entry): bool
    {
        foreach ($this->filters as $filter) {
            if (! $filter($entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guard against unbounded memory in a request that records a huge number of
     * entries: resolve lazies / force-flush mid-request when over the cap.
     */
    protected function ingestWhenOverBufferSize(): void
    {
        if ($this->evaluatingBuffer) {
            return;
        }

        $buffer = (int) config('vigilance.apm.ingest.buffer', 5000);

        $this->evaluatingBuffer = true;

        try {
            if ($this->entries->count() + $this->lazy->count() > $buffer) {
                $this->resolveLazyEntries();
            }

            if ($this->entries->count() > $buffer) {
                $this->ingest();
            }
        } finally {
            $this->evaluatingBuffer = false;
        }
    }

    /** @return array{0:int,1:int} */
    protected function trimLotteryOdds(): array
    {
        $odds = (array) config('vigilance.apm.ingest.trim.lottery', [1, 1000]);

        return [(int) ($odds[0] ?? 1), (int) ($odds[1] ?? 1000)];
    }
}
