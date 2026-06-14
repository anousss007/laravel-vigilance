<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Vigilance\Models\ScheduledTaskMonitor;
use Vigilance\Support\ScheduledTaskName;

class ScheduleSyncCommand extends Command
{
    protected $signature = 'vigilance:schedule-sync {--keep-old : Do not delete monitors that are no longer in the schedule}';

    protected $description = 'Sync the defined scheduled tasks into Vigilance monitors so lateness/failures can be tracked.';

    public function handle(Schedule $schedule): int
    {
        $seen = [];

        foreach ($schedule->events() as $event) {
            $name = ScheduledTaskName::for($event);
            $seen[] = $name;

            $monitor = ScheduledTaskMonitor::query()->firstOrNew(['name' => $name]);

            $monitor->type = ScheduledTaskName::type($event);
            $monitor->cron_expression = $event->expression;
            $monitor->timezone = $this->timezone($event);

            if (! $monitor->exists) {
                $monitor->monitored = true;
            }

            $monitor->save();
        }

        $removed = 0;

        if (! $this->option('keep-old')) {
            $removed = ScheduledTaskMonitor::query()
                ->whereNotIn('name', $seen ?: [''])
                ->delete();
        }

        $this->info(sprintf('Synced %d scheduled task(s)%s.', count($seen), $removed ? ", removed {$removed} stale" : ''));

        return self::SUCCESS;
    }

    protected function timezone(object $event): ?string
    {
        $tz = $event->timezone ?? null;

        if ($tz === null) {
            return null;
        }

        return $tz instanceof \DateTimeZone ? $tz->getName() : (string) $tz;
    }
}
