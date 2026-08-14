<?php

namespace Vigilance\Apm\Recorders;

use DateTimeInterface;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\ResolvesRequests;
use Vigilance\Apm\Recorders\Concerns\Sampling;

/**
 * Laravel Debugbar's counters, but for production and aggregated per route: how
 * many queries a page ran, how long it spent in the database, how much memory it
 * peaked at, and how many Eloquent models it hydrated.
 *
 * This covers the gap tracing structurally cannot. A trace is only persisted
 * when it is head-sampled, slow or errored, so a route that quietly runs 180
 * distinct queries in 400 ms — no N+1 shape, not slow enough to keep — never
 * shows up there. Counting happens here on *every* request instead: the hot path
 * is two increments per query, and the metrics are written once in the terminate
 * phase, after the response has been sent.
 *
 * Unlike the other recorders this one is stateful across a request, so it is
 * bound as a singleton and flushed at every unit boundary.
 */
class RequestProfile extends Recorder
{
    use Ignores;
    use ResolvesRequests;
    use Sampling;

    protected int $queries = 0;

    protected float $queryMs = 0.0;

    protected int $models = 0;

    public function register(Apm $apm): void
    {
        $app = $this->container();
        $events = $app->make('events');

        $events->listen(QueryExecuted::class, function (QueryExecuted $event) {
            // Never count our own storage traffic — issue capture, breadcrumbs
            // and the log explorer all write mid-request. Matching on the table
            // prefix also covers the dedicated-connection setup, where the table
            // names are identical on another connection.
            if (str_contains($event->sql, 'vigilance_')) {
                return;
            }

            $this->queries++;
            $this->queryMs += $event->time;
        });

        if ($this->recorderConfig('models', true)) {
            // The one hook here that fires per model rather than per query.
            $events->listen('eloquent.retrieved: *', function (string $event) {
                if (! str_starts_with($event, 'eloquent.retrieved: Vigilance\\')) {
                    $this->models++;
                }
            });
        }

        // Queue workers have no request boundary to flush the counters.
        $events->listen(Looping::class, fn () => $this->flush());
        $events->listen(WorkerStopping::class, fn () => $this->flush());

        // An Octane worker otherwise carries both the counters and the memory
        // high-water mark across requests.
        if (class_exists('Laravel\Octane\Events\RequestReceived')) {
            $events->listen('Laravel\Octane\Events\RequestReceived', function () {
                $this->flush();
                memory_reset_peak_usage();
            });
        }

        $hook = fn ($startedAt, $request, $response) => $this->record($startedAt, $request, $response);

        $app->afterResolving(Kernel::class, fn (Kernel $kernel) => $kernel->whenRequestLifecycleIsLongerThan(-1, $hook));

        if ($app->resolved(Kernel::class)) {
            $app->make(Kernel::class)->whenRequestLifecycleIsLongerThan(-1, $hook);
        }
    }

    public function record(DateTimeInterface $startedAt, Request $request, Response $response): void
    {
        $queries = $this->queries;
        $queryMs = $this->queryMs;
        $models = $this->models;
        $peak = memory_get_peak_usage(true);

        $this->flush();

        // Rebase the high-water mark so the next request on a long-lived worker
        // (Octane, RoadRunner) is measured on its own, rather than inheriting the
        // peak of the heaviest request the process has ever served. Under PHP-FPM
        // the peak is already per-request, where this is a no-op. Only done on
        // the HTTP path: queue workers measure job memory through their own
        // capture recorder, which must keep seeing an untouched peak.
        memory_reset_peak_usage();

        if (! $request->route() instanceof Route || ! $this->shouldSample()) {
            return;
        }

        $path = $this->resolveRoutePath($request);

        if ($this->shouldIgnore($path)) {
            return;
        }

        $timestamp = $startedAt->getTimestamp();
        $key = (string) json_encode([$request->method(), $path]);

        $this->apm->record('request_queries', $key, $queries, $timestamp)->count()->avg()->max();
        $this->apm->record('request_memory', $key, (int) round($peak / 1024), $timestamp)->count()->avg()->max();

        // A route that touched no database is fully described by the two metrics
        // above; skip the rows that could only ever read zero.
        if ($queries > 0) {
            $this->apm->record('request_db_ms', $key, (int) round($queryMs), $timestamp)->avg()->max();
            $this->apm->record('request_models', $key, $models, $timestamp)->avg()->max();
        }
    }

    /**
     * Clear the per-unit counters. Called at every unit boundary, so a queue
     * worker doesn't carry one job's query tally into the next.
     */
    public function flush(): void
    {
        $this->queries = 0;
        $this->queryMs = 0.0;
        $this->models = 0;
    }
}
