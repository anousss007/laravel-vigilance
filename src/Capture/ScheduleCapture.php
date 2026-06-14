<?php

namespace Vigilance\Capture;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Carbon;
use Vigilance\Models\ScheduledTaskMonitor;
use Vigilance\Support\ScheduledTaskName;

/**
 * Keeps the vigilance_scheduled_tasks monitors in step with the live schedule
 * lifecycle (started / finished / failed / skipped). The actual command/job
 * executions are captured separately by CommandCapture / JobCapture, so this
 * layer only maintains scheduling health (last run, duration, lateness).
 */
class ScheduleCapture
{
    public function __construct(protected Recorder $recorder) {}

    public function register(): void
    {
        $events = app('events');

        $events->listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $e) => $this->starting($e->task));
        $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $e) => $this->finished($e->task, $e->runtime ?? null));
        $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => $this->failed($e->task));
        $events->listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $e) => $this->skipped($e->task));
    }

    protected function starting(object $task): void
    {
        $this->update($task, fn (ScheduledTaskMonitor $m) => $m->last_started_at = Carbon::now());
    }

    protected function finished(object $task, ?float $runtime): void
    {
        $this->update($task, function (ScheduledTaskMonitor $m) use ($runtime) {
            $m->last_finished_at = Carbon::now();

            if ($runtime !== null) {
                $m->last_duration_ms = (int) round($runtime * 1000);
            }
        });
    }

    protected function failed(object $task): void
    {
        $this->update($task, function (ScheduledTaskMonitor $m) {
            $m->last_failed_at = Carbon::now();
            $m->last_finished_at = Carbon::now();
        });
    }

    protected function skipped(object $task): void
    {
        $this->update($task, fn (ScheduledTaskMonitor $m) => $m->last_skipped_at = Carbon::now());
    }

    protected function update(object $task, \Closure $mutate): void
    {
        try {
            $name = ScheduledTaskName::for($task);

            $monitor = ScheduledTaskMonitor::query()->firstOrNew(['name' => $name]);

            if (! $monitor->exists) {
                $monitor->type = ScheduledTaskName::type($task);
                $monitor->cron_expression = $task->expression ?? null;
                $monitor->timezone = $this->timezone($task);
                $monitor->monitored = true;
            }

            $mutate($monitor);
            $monitor->save();
        } catch (\Throwable) {
            // never break the scheduler
        }
    }

    protected function timezone(object $task): ?string
    {
        $tz = $task->timezone ?? null;

        if ($tz === null) {
            return null;
        }

        return $tz instanceof \DateTimeZone ? $tz->getName() : (string) $tz;
    }
}
