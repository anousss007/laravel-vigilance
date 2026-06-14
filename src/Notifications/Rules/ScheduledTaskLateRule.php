<?php

namespace Vigilance\Notifications\Rules;

use Vigilance\Models\ScheduledTaskMonitor;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires for each monitored scheduled task that is overdue (missed its expected
 * run beyond its grace window) or whose last run failed.
 */
class ScheduledTaskLateRule implements AlertRule
{
    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.scheduled_task_late.enabled', true)) {
            return;
        }

        $monitors = ScheduledTaskMonitor::query()->where('monitored', true)->get();

        foreach ($monitors as $monitor) {
            if ($monitor->isLate()) {
                yield new Alert(
                    key: 'schedule_late:'.$monitor->name,
                    title: 'Scheduled task overdue',
                    message: "Scheduled task [{$monitor->name}] is overdue (expected to have run by now).",
                    level: 'warning',
                );
            } elseif ($monitor->lastRunFailed()) {
                yield new Alert(
                    key: 'schedule_failed:'.$monitor->name,
                    title: 'Scheduled task failed',
                    message: "Scheduled task [{$monitor->name}] last run failed.",
                    level: 'critical',
                );
            }
        }
    }
}
