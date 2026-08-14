<?php

namespace Vigilance\Apm\Recorders\Concerns;

use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

/**
 * Shared plumbing for the recorders that hook the HTTP kernel: resolving the
 * bounded route key an entry is filed under, and measuring the request's
 * wall-clock duration.
 */
trait ResolvesRequests
{
    /**
     * The matched route URI (not the concrete path), so a route hit a million
     * times collapses to a single bounded key instead of a million paths.
     */
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
                return '/'.ltrim($this->routeUriFor($path), '/').' (livewire)';
            }
        }

        return '/'.ltrim($uri, '/');
    }

    /**
     * Collapse a concrete page path to the route URI that serves it
     * ("/orders/42" => "orders/{order}").
     *
     * The referrer is a real URL, so without this every visited record mints its
     * own metric key and a Livewire-heavy app grows unbounded cardinality — the
     * very thing keying by route exists to prevent. Falls back to the concrete
     * path when nothing matches (an external or stale referrer).
     *
     * Runs in the terminate phase, after the response is sent, and uses the
     * router's own matcher so it stays a single compiled lookup under
     * `route:cache` rather than a scan of every registered route.
     */
    protected function routeUriFor(string $path): string
    {
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;

        try {
            // match() binds the matched route to this probe request. Harmless
            // here: we are past the response, and the router re-binds a route
            // against the real request before ever using it again.
            return $this->container()->make('router')
                ->getRoutes()
                ->match(Request::create($path, 'GET'))
                ->uri();
        } catch (Throwable) {
            // No such route (external or stale referrer), or a routing error a
            // monitoring path must never let bubble into the host app.
            return $path;
        }
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
