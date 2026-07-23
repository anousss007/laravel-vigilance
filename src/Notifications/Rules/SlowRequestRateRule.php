<?php

namespace Vigilance\Notifications\Rules;

use Carbon\CarbonInterval;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires when the number of slow requests over the last hour exceeds the
 * configured count. Off by default (high-traffic apps will want to tune it).
 */
class SlowRequestRateRule implements AlertRule
{
    public function __construct(protected Storage $storage) {}

    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.slow_request_rate.enabled', false) || ! config('vigilance.apm.enabled', true)) {
            return;
        }

        $threshold = (int) config('vigilance.alerts.rules.slow_request_rate.count', 100);

        $count = (int) round($this->storage->aggregateTotal('slow_request', 'count', CarbonInterval::hour()));

        if ($count < $threshold) {
            return;
        }

        // Name the slowest routes (method path × count, worst ms) so you know
        // where to look.
        $top = [];
        foreach ($this->storage->aggregate('slow_request', ['count', 'max'], CarbonInterval::hour(), orderBy: 'count', limit: 3) as $row) {
            $decoded = json_decode((string) $row->key, true);
            $route = is_array($decoded) ? trim(($decoded[0] ?? '').' '.($decoded[1] ?? '')) : (string) $row->key;
            $top[] = $route.' ('.(int) $row->count.'×, max '.(int) $row->max.'ms)';
        }

        $detail = $top !== [] ? ' Slowest: '.implode('; ', $top).'.' : '';

        yield new Alert(
            key: 'slow_request_rate',
            title: 'Many slow requests',
            message: "{$count} slow requests in the last hour (threshold {$threshold}).{$detail}",
            level: 'warning',
        );
    }
}
