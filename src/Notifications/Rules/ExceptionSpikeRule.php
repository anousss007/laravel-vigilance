<?php

namespace Vigilance\Notifications\Rules;

use Carbon\CarbonInterval;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires when the number of reported exceptions over the last hour exceeds the
 * configured count.
 */
class ExceptionSpikeRule implements AlertRule
{
    public function __construct(protected Storage $storage) {}

    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.exception_spike.enabled', true) || ! config('vigilance.apm.enabled', true)) {
            return;
        }

        $threshold = (int) config('vigilance.alerts.rules.exception_spike.count', 50);

        $count = (int) round($this->storage->aggregateTotal('exception', 'count', CarbonInterval::hour()));

        if ($count < $threshold) {
            return;
        }

        yield new Alert(
            key: 'exception_spike',
            title: 'Exception spike',
            message: "{$count} exceptions reported in the last hour (threshold {$threshold}).",
            level: 'critical',
        );
    }
}
