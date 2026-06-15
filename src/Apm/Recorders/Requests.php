<?php

namespace Vigilance\Apm\Recorders;

use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\Sampling;

/**
 * Records every request (sampled), keyed by [method, route], for the Routes
 * performance page: throughput, average & max latency, an Apdex score, a 5xx
 * error count, and per-request durations (the entry value) from which exact
 * p50/p95/p99 are computed by the Routes page. Keying by the matched route URI
 * keeps cardinality bounded.
 */
class Requests extends Recorder
{
    use Ignores;
    use Sampling;

    public function register(Apm $apm): void
    {
        $app = $this->container();

        $hook = fn ($startedAt, $request, $response) => $this->record($startedAt, $request, $response);

        $app->afterResolving(Kernel::class, fn (Kernel $kernel) => $kernel->whenRequestLifecycleIsLongerThan(-1, $hook));

        if ($app->resolved(Kernel::class)) {
            $app->make(Kernel::class)->whenRequestLifecycleIsLongerThan(-1, $hook);
        }
    }

    public function record(DateTimeInterface $startedAt, Request $request, Response $response): void
    {
        if (! $request->route() instanceof Route || ! $this->shouldSample()) {
            return;
        }

        $path = $this->resolveRoutePath($request);

        if ($this->shouldIgnore($path)) {
            return;
        }

        $duration = $this->durationMs($startedAt);
        $timestamp = $startedAt->getTimestamp();
        $key = (string) json_encode([$request->method(), $path]);

        $this->apm->record('request', $key, $duration, $timestamp)->count()->avg()->max();
        $this->apm->record('request_apdex', $key, $this->apdexScore($duration), $timestamp)->avg();

        if ($response->getStatusCode() >= 500) {
            $this->apm->record('request_error', $key, $response->getStatusCode(), $timestamp)->count();
        }
    }

    /** A per-request Apdex contribution: 100 satisfied, 50 tolerating, 0 frustrated (averaged → Apdex × 100). */
    protected function apdexScore(int $duration): int
    {
        $threshold = (int) $this->recorderConfig('apdex_threshold', 300);

        return match (true) {
            $duration <= $threshold => 100,
            $duration <= $threshold * 4 => 50,
            default => 0,
        };
    }

    protected function resolveRoutePath(Request $request): string
    {
        $route = $request->route();
        $uri = $route instanceof Route ? $route->uri() : $request->path();

        // Livewire update requests all hit one endpoint; attribute them to the
        // page they happened on (the referrer) instead of "/livewire/update".
        if (str_contains($uri, 'livewire/update') || str_contains($uri, 'livewire/message')) {
            $referer = (string) $request->headers->get('referer', '');
            $path = $referer !== '' ? parse_url($referer, PHP_URL_PATH) : null;

            if (is_string($path) && $path !== '') {
                return '/'.ltrim(rtrim($path, '/'), '/').' (livewire)';
            }
        }

        return '/'.ltrim($uri, '/');
    }

    protected function durationMs(DateTimeInterface $startedAt): int
    {
        $start = (float) $startedAt->format('U.u');

        return (int) round(max(0, (microtime(true) - $start) * 1000));
    }

    protected function container(): Container
    {
        return $this->apm->container();
    }
}
