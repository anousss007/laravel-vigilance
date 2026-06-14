<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\Sampling;
use Vigilance\Apm\Recorders\Concerns\Thresholds;

/**
 * Records requests slower than the threshold, keyed by [method, route]. It hooks
 * the HTTP kernel's terminate phase (whenRequestLifecycleIsLongerThan), so it
 * costs nothing on the hot path, and keys by the matched route URI so a route hit
 * a million times collapses to a single bounded key (not a million paths).
 */
class SlowRequests extends Recorder
{
    use Ignores;
    use Sampling;
    use Thresholds;

    public function register(Apm $apm): void
    {
        $app = $this->container();

        $app->afterResolving(Kernel::class, function (Kernel $kernel) {
            $kernel->whenRequestLifecycleIsLongerThan(-1, function ($startedAt, $request, $response) {
                $this->record($startedAt, $request, $response);
            });
        });

        if ($app->resolved(Kernel::class)) {
            $app->make(Kernel::class)->whenRequestLifecycleIsLongerThan(-1, function ($startedAt, $request, $response) {
                $this->record($startedAt, $request, $response);
            });
        }
    }

    public function record(\DateTimeInterface $startedAt, Request $request, Response $response): void
    {
        if (! $request->route() instanceof Route || ! $this->shouldSample()) {
            return;
        }

        $path = $this->resolveRoutePath($request);
        $duration = $this->durationMs($startedAt);

        if ($this->shouldIgnore($path) || $this->underThreshold($duration, $path)) {
            return;
        }

        $key = json_encode([$request->method(), $path]);

        $this->apm->record('slow_request', (string) $key, $duration, $startedAt->getTimestamp())->max()->count();

        if ($user = $this->apm->resolveUser()) {
            $this->apm->record('slow_user_request', (string) $user['id'], null, $startedAt->getTimestamp())->count();
            $this->apm->set('user', (string) $user['id'], (string) json_encode($user), $startedAt->getTimestamp());
        }
    }

    protected function resolveRoutePath(Request $request): string
    {
        $route = $request->route();
        $uri = $route instanceof Route ? $route->uri() : $request->path();

        // Livewire update requests all hit the same endpoint, which would
        // collapse every component interaction into "/livewire/update". Attribute
        // them to the page they happened on (the referrer) instead, so a slow
        // Livewire component shows up against its real route.
        if (str_contains($uri, 'livewire/update') || str_contains($uri, 'livewire/message')) {
            $referer = (string) $request->headers->get('referer', '');
            $path = $referer !== '' ? parse_url($referer, PHP_URL_PATH) : null;

            if (is_string($path) && $path !== '') {
                return '/'.ltrim(rtrim($path, '/'), '/').' (livewire)';
            }
        }

        return '/'.ltrim($uri, '/');
    }

    protected function durationMs(\DateTimeInterface $startedAt): int
    {
        $start = (float) $startedAt->format('U.u');

        return (int) round(max(0, (microtime(true) - $start) * 1000));
    }

    protected function container(): Container
    {
        return $this->apm->container();
    }
}
