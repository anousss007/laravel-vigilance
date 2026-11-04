<?php

namespace Vigilance\Support;

use Illuminate\Support\Str;

/**
 * Resolves the grace window (minutes) for a scheduled task monitor from
 * config: the first "vigilance.schedule.grace" pattern matching the task
 * name, else "vigilance.schedule.grace_minutes".
 */
class ScheduledTaskGrace
{
    public static function for(string $name): int
    {
        foreach ((array) config('vigilance.schedule.grace', []) as $pattern => $minutes) {
            if (is_numeric($minutes) && Str::is((string) $pattern, $name)) {
                return max(0, (int) $minutes);
            }
        }

        return max(0, (int) config('vigilance.schedule.grace_minutes', 5));
    }
}
