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
                    message: $this->overdueMessage($monitor),
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

    protected function overdueMessage(ScheduledTaskMonitor $monitor): string
    {
        $tz = $monitor->timezone ?: config('app.timezone', 'UTC');
        $format = fn ($at) => $at->copy()->setTimezone($tz)->format('Y-m-d H:i:s T');

        $expectedBy = $monitor->expectedBy() ?? now();
        $lastSeen = $monitor->last_started_at;

        $message = "Scheduled task [{$monitor->name}] is overdue: it should have started by "
            .$format($expectedBy->copy()->subMinutes($monitor->grace_time_minutes))
            ." ({$monitor->grace_time_minutes} min grace).";

        $message .= $lastSeen
            ? ' Last started '.$format($lastSeen).'.'
            : ' It has not run since monitoring began.';

        return $message.' If it is just queued behind slower tasks, raise vigilance.schedule.grace or run those with ->runInBackground().';
    }
}
