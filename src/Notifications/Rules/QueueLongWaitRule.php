<?php

namespace Vigilance\Notifications\Rules;

use Vigilance\Metrics\Workload;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires when a queue's estimated time-to-clear exceeds the threshold.
 */
class QueueLongWaitRule implements AlertRule
{
    public function __construct(protected Workload $workload) {}

    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.queue_long_wait.enabled', true)) {
            return;
        }

        $threshold = (int) config('vigilance.alerts.rules.queue_long_wait.seconds', 60);

        foreach ($this->workload->queues() as $queue) {
            $clearMs = $queue['time_to_clear_ms'] ?? null;

            if ($clearMs === null) {
                continue;
            }

            $seconds = (int) round($clearMs / 1000);

            if ($seconds < $threshold) {
                continue;
            }

            $name = ($queue['connection_name'] ?? 'default').':'.$queue['queue'];

            yield new Alert(
                key: 'queue_long_wait:'.$name,
                title: 'Queue backing up',
                message: "Queue [{$name}] is backing up — estimated time to clear {$seconds}s "
                    ."(depth {$queue['depth']}, {$queue['workers']} worker(s)).",
                level: 'warning',
            );
        }
    }
}
