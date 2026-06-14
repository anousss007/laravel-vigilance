<?php

namespace Vigilance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;

/**
 * A unified record of a single job, command or scheduled-task run.
 *
 * @property int $id
 * @property string $uuid
 * @property RunType $type
 * @property ?string $name
 * @property ?string $display_name
 * @property RunStatus $status
 * @property ?string $connection_name
 * @property ?string $queue
 * @property int $attempt
 * @property ?int $retry_of
 * @property ?array<string, mixed> $parameters
 * @property ?string $payload_raw
 * @property ?array<int, string> $tags
 * @property ?string $output
 * @property ?int $exit_code
 * @property ?string $exception_class
 * @property ?string $exception_message
 * @property ?string $exception
 * @property ?int $failure_group_id
 * @property ?string $batch_id
 * @property ?string $via
 * @property ?string $caused_by
 * @property ?int $memory_peak
 * @property ?int $cpu_time_ms
 * @property ?Carbon $queued_at
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?int $wait_ms
 * @property ?int $duration_ms
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Run extends VigilanceModel
{
    protected $table = 'vigilance_runs';

    protected $casts = [
        'type' => RunType::class,
        'status' => RunStatus::class,
        'parameters' => 'array',
        'tags' => 'array',
        'exit_code' => 'integer',
        'attempt' => 'integer',
        'memory_peak' => 'integer',
        'cpu_time_ms' => 'integer',
        'wait_ms' => 'integer',
        'duration_ms' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function tagModels(): HasMany
    {
        return $this->hasMany(RunTag::class, 'run_id');
    }

    public function failureGroup(): BelongsTo
    {
        return $this->belongsTo(FailureGroup::class, 'failure_group_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', RunStatus::Failed->value);
    }

    public function scopeOfType($query, RunType $type)
    {
        return $query->where('type', $type->value);
    }

    public function isManual(): bool
    {
        return $this->via === 'manual';
    }
}
