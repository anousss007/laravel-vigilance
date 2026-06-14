<?php

namespace Vigilance\Support;

/**
 * Derives a stable, human-readable name and type for a scheduled task from a
 * Laravel scheduler Event, normalizing the messy command/closure/job shapes.
 */
class ScheduledTaskName
{
    public static function for(object $task): string
    {
        $command = $task->command ?? null;

        if (is_string($command) && $command !== '') {
            if (str_contains($command, 'artisan')) {
                $after = preg_replace('/^.*artisan[\'"]?\s+/', '', $command) ?? '';

                return trim($after) !== '' ? trim($after) : $command;
            }

            return $command;
        }

        $description = $task->description ?? null;

        if (is_string($description) && $description !== '') {
            return $description;
        }

        return 'Closure ('.($task->expression ?? '* * * * *').')';
    }

    public static function type(object $task): string
    {
        if (! empty($task->command)) {
            return 'command';
        }

        $description = $task->description ?? null;

        if (is_string($description) && class_exists($description)) {
            return 'job';
        }

        return 'closure';
    }
}
