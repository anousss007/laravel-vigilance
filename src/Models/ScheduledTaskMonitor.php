<?php

namespace Vigilance\Models;

use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property ?string $type
 * @property ?string $cron_expression
 * @property ?string $timezone
 * @property int $grace_time_minutes
 * @property bool $monitored
 * @property ?Carbon $last_started_at
 * @property ?Carbon $last_finished_at
 * @property ?Carbon $last_failed_at
 * @property ?Carbon $last_skipped_at
 * @property ?int $last_duration_ms
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ScheduledTaskMonitor extends VigilanceModel
{
    protected $table = 'vigilance_scheduled_tasks';

    protected $casts = [
        'grace_time_minutes' => 'integer',
        'monitored' => 'boolean',
        'last_started_at' => 'datetime',
        'last_finished_at' => 'datetime',
        'last_failed_at' => 'datetime',
        'last_skipped_at' => 'datetime',
        'last_duration_ms' => 'integer',
    ];

    public function nextRunAt(?CarbonInterface $from = null): ?\DateTimeInterface
    {
        if (! $this->cron_expression) {
            return null;
        }

        $base = $from ?? now($this->timezone);

        return (new CronExpression($this->cron_expression))
            ->getNextRunDate($base, 0, false, $this->timezone);
    }

    /**
     * The task is "late" if its next expected run (computed from the last
     * finish, or creation if it never finished) plus the grace time is in the
     * past. Covers both "ran too long / overdue" and "never ran".
     */
    public function isLate(): bool
    {
        if (! $this->monitored || ! $this->cron_expression) {
            return false;
        }

        $reference = $this->last_finished_at ?? $this->created_at;

        if (! $reference) {
            return false;
        }

        $expectedNext = $this->nextRunAt($reference);

        if (! $expectedNext) {
            return false;
        }

        return now()->greaterThan(
            Carbon::instance($expectedNext)->addMinutes($this->grace_time_minutes)
        );
    }

    public function lastRunFailed(): bool
    {
        return $this->last_failed_at !== null
            && (! $this->last_started_at || $this->last_failed_at->gte($this->last_started_at->subSecond()));
    }
}
