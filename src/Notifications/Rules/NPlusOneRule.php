<?php

namespace Vigilance\Notifications\Rules;

use Carbon\CarbonInterval;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires when N+1 query patterns (detected by the tracer and promoted to the
 * "n_plus_one" APM signal) recur for a route/job over the window — a first-class
 * alert for a problem you'd otherwise have to catch one trace at a time.
 */
class NPlusOneRule implements AlertRule
{
    public function __construct(protected Storage $storage) {}

    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.n_plus_one.enabled', false)) {
            return;
        }

        $minOccurrences = max(1, (int) config('vigilance.alerts.rules.n_plus_one.min_occurrences', 5));
        $window = (string) config('vigilance.alerts.rules.n_plus_one.window', '1h');

        $rows = $this->storage->aggregate('n_plus_one', ['count', 'max'], $this->interval($window), orderBy: 'count', limit: 20);

        foreach ($rows as $row) {
            $occurrences = (int) $row->count;

            if ($occurrences < $minOccurrences) {
                continue;
            }

            $name = (string) $row->key;
            $worst = (int) $row->max;

            yield new Alert(
                key: 'n_plus_one:'.$name,
                title: 'N+1 query pattern',
                message: "N+1 queries detected on [{$name}] — seen {$occurrences} time(s) in the last {$window}, "
                    ."worst trace repeated a query {$worst} times.",
                level: 'warning',
            );
        }
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
