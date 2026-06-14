<?php

namespace Vigilance\Tracing\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vigilance\Tracing\Tracer;

/**
 * Starts a trace at the very front of the middleware stack (so early-middleware
 * queries are captured) and finishes it in terminate() — after the response is
 * sent — where the persist/drop decision is made. Prepended globally by the
 * service provider when tracing + request capture are enabled.
 */
class TraceRequests
{
    public function __construct(protected Tracer $tracer) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->shouldTrace($request)) {
            $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

            $this->tracer->start('request', $request->method().' '.$this->path($request), $start, [
                'method' => $request->method(),
                'path' => $this->path($request),
            ]);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->tracer->sampling()) {
            return;
        }

        $status = $response->getStatusCode();

        $this->tracer->setAttributes([
            'status' => $status,
            'route' => $request->route()?->uri(),
        ]);

        $this->tracer->finish($status >= 500 ? 'error' : 'ok');
    }

    protected function shouldTrace(Request $request): bool
    {
        if (! ($this->tracer->container()->make('config')->get('vigilance.tracing.capture.requests', true))) {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');

        foreach ((array) $this->tracer->container()->make('config')->get('vigilance.tracing.ignore', []) as $pattern) {
            if (@preg_match((string) $pattern, $path) === 1) {
                return false;
            }
        }

        return true;
    }

    protected function path(Request $request): string
    {
        return '/'.ltrim($request->path(), '/');
    }
}
