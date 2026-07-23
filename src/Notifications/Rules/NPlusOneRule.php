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

            // The key encodes the route/job, the exact SQL and the app line.
            $decoded = json_decode((string) $row->key, true);
            $name = is_array($decoded) ? (string) ($decoded['name'] ?? $row->key) : (string) $row->key;
            $sql = is_array($decoded) ? (string) ($decoded['sql'] ?? '') : '';
            $caller = is_array($decoded) ? ($decoded['caller'] ?? null) : null;
            $worst = (int) $row->max;

            $detail = $sql !== '' ? " Query: {$sql}" : '';
            $detail .= $caller ? " (at {$caller})" : '';

            yield new Alert(
                key: 'n_plus_one:'.$name.'|'.($caller ?? $sql),
                title: 'N+1 query pattern',
                message: "N+1 queries on [{$name}] — a query ran {$worst} times in one request/job, "
                    ."seen {$occurrences} time(s) in the last {$window}.{$detail}",
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
