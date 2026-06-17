<?php

namespace Vigilance\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Models\ScheduledTaskMonitor;

#[Description('Scheduled-task monitors: each defined scheduled task with its cron expression, last run time/duration, whether it is currently late (overdue past its grace window — a dead-man\'s switch), and whether its last run failed.')]
#[IsReadOnly]
class ScheduleTool extends Tool
{
    public function handle(Request $request): Response
    {
        $tasks = ScheduledTaskMonitor::query()->orderBy('name')->get();

        return $this->json([
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn (ScheduledTaskMonitor $t): array => [
                'name' => $t->name,
                'type' => $t->type,
                'cron' => $t->cron_expression,
                'timezone' => $t->timezone,
                'grace_minutes' => $t->grace_time_minutes,
                'monitored' => $t->monitored,
                'is_late' => $t->isLate(),
                'last_run_failed' => $t->lastRunFailed(),
                'last_duration_ms' => $t->last_duration_ms,
                'last_started_at' => $this->date($t->last_started_at),
                'last_finished_at' => $this->date($t->last_finished_at),
                'last_failed_at' => $this->date($t->last_failed_at),
            ])->all(),
        ]);
    }
}
