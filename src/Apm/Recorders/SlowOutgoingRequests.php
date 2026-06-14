<?php

namespace Vigilance\Apm\Recorders;

use GuzzleHttp\Promise\RejectedPromise;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Recorders\Concerns\Groups;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\Sampling;
use Vigilance\Apm\Recorders\Concerns\Thresholds;

/**
 * Records outgoing HTTP calls (Laravel's Http client) slower than the threshold,
 * keyed by [method, grouped-uri]. It installs a global Guzzle middleware that
 * times every request and defers the heavy work via lazy(), so it never blocks
 * the calling request.
 */
class SlowOutgoingRequests extends Recorder
{
    use Groups;
    use Ignores;
    use Sampling;
    use Thresholds;

    public function register(Apm $apm): void
    {
        $app = $apm->container();

        $app->afterResolving(Factory::class, function (Factory $factory) {
            $factory->globalMiddleware($this->middleware());
        });

        if ($app->resolved(Factory::class)) {
            $app->make(Factory::class)->globalMiddleware($this->middleware());
        }
    }

    public function record(RequestInterface $request, int $startedAtMs): void
    {
        $timestamp = time();
        $endedAtMs = (int) round(microtime(true) * 1000);
        $method = $request->getMethod();
        $uri = (string) $request->getUri();

        $this->apm->lazy(function () use ($startedAtMs, $endedAtMs, $timestamp, $method, $uri) {
            $duration = $endedAtMs - $startedAtMs;

            if (! $this->shouldSample() || $this->shouldIgnore($uri) || $this->underThreshold($duration, $uri)) {
                return;
            }

            $key = json_encode([$method, $this->group($uri)]);

            $this->apm->record('slow_outgoing_request', (string) $key, $duration, $timestamp)->max()->count();
        });
    }

    protected function middleware(): callable
    {
        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
            $startedAtMs = (int) round(microtime(true) * 1000);

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($request, $startedAtMs) {
                    $this->apm->rescue(fn () => $this->record($request, $startedAtMs));

                    return $response;
                },
                function (Throwable $exception) use ($request, $startedAtMs) {
                    $this->apm->rescue(fn () => $this->record($request, $startedAtMs));

                    return new RejectedPromise($exception);
                },
            );
        };
    }
}
