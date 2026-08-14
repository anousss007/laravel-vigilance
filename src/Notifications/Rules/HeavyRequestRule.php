<?php

namespace Vigilance\Notifications\Rules;

use Carbon\CarbonInterval;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires when a route is expensive rather than slow: it runs too many queries per
 * request, or peaks too high on memory. Both are things you would have caught in
 * the Debugbar locally and would otherwise only notice in production once the
 * page got slow enough — or once the worker hit its memory limit.
 *
 * Reads the per-route cost metrics written by the RequestProfile recorder.
 */
class HeavyRequestRule implements AlertRule
{
    public function __construct(protected Storage $storage) {}

    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.heavy_request.enabled', false)) {
            return;
        }

        $window = (string) config('vigilance.alerts.rules.heavy_request.window', '1h');
        $interval = $this->interval($window);
        $minRequests = max(1, (int) config('vigilance.alerts.rules.heavy_request.min_requests', 10));
        $maxQueries = (int) config('vigilance.alerts.rules.heavy_request.queries', 100);
        $maxMemoryMb = (int) config('vigilance.alerts.rules.heavy_request.memory_mb', 128);

        yield from $this->queryAlerts($interval, $window, $minRequests, $maxQueries);
        yield from $this->memoryAlerts($interval, $window, $minRequests, $maxMemoryMb);
    }

    /**
     * @return iterable<Alert>
     */
    protected function queryAlerts(CarbonInterval $interval, string $window, int $minRequests, int $threshold): iterable
    {
        if ($threshold <= 0) {
            return;
        }

        foreach ($this->storage->aggregate('request_queries', ['count', 'avg', 'max'], $interval, orderBy: 'max', limit: 20) as $row) {
            $worst = (int) $row->max;

            if ((int) $row->count < $minRequests || $worst < $threshold) {
                continue;
            }

            $route = $this->route((string) $row->key);
            $average = round((float) $row->avg, 1);

            yield new Alert(
                key: 'heavy_request:queries:'.$route,
                title: 'Route runs too many queries',
                message: "[{$route}] ran {$worst} queries in a single request (avg {$average}) over the last {$window}, "
                    ."above the {$threshold}-query threshold. Look for a missing eager load or an unbatched loop.",
                level: 'warning',
            );
        }
    }

    /**
     * @return iterable<Alert>
     */
    protected function memoryAlerts(CarbonInterval $interval, string $window, int $minRequests, int $thresholdMb): iterable
    {
        if ($thresholdMb <= 0) {
            return;
        }

        $thresholdKb = $thresholdMb * 1024;

        foreach ($this->storage->aggregate('request_memory', ['count', 'avg', 'max'], $interval, orderBy: 'max', limit: 20) as $row) {
            $worstKb = (int) $row->max;

            if ((int) $row->count < $minRequests || $worstKb < $thresholdKb) {
                continue;
            }

            $route = $this->route((string) $row->key);
            $worst = round($worstKb / 1024, 1);
            $average = round(((float) $row->avg) / 1024, 1);

            yield new Alert(
                key: 'heavy_request:memory:'.$route,
                title: 'Route peaks too high on memory',
                message: "[{$route}] peaked at {$worst} MB in a single request (avg {$average} MB) over the last {$window}, "
                    ."above the {$thresholdMb} MB threshold. Look for an unchunked collection or a large in-memory payload.",
                level: 'warning',
            );
        }
    }

    /**
     * The metric key is json_encode([method, route uri]); render it as "GET /users/{user}".
     */
    protected function route(string $key): string
    {
        $decoded = json_decode($key, true);

        if (! is_array($decoded)) {
            return mb_substr($key, 0, 180);
        }

        // Bounded: the alert key is stored in a string(255) column.
        return mb_substr(trim(((string) ($decoded[0] ?? '')).' '.((string) ($decoded[1] ?? ''))), 0, 180);
    }

    protected function interval(string $window): CarbonInterval
    {
        if (! preg_match('/^(\d+)\s*(m|h|d)$/i', trim($window), $m)) {
            return CarbonInterval::hour();
        }

        $value = max(1, (int) $m[1]);

        return match (strtolower($m[2])) {
            'm' => CarbonInterval::minutes($value),
            'd' => CarbonInterval::days($value),
            default => CarbonInterval::hours($value),
        };
    }
}
