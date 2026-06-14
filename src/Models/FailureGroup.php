<?php

namespace Vigilance\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $signature
 * @property ?string $type
 * @property ?string $name
 * @property ?string $exception_class
 * @property ?string $message
 * @property int $occurrences
 * @property ?string $priority
 * @property ?string $assignee
 * @property ?Carbon $acknowledged_at
 * @property ?Carbon $first_seen_at
 * @property ?Carbon $last_seen_at
 * @property ?Carbon $resolved_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class FailureGroup extends VigilanceModel
{
    protected $table = 'vigilance_failure_groups';

    protected $casts = [
        'occurrences' => 'integer',
        'acknowledged_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class, 'failure_group_id');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function status(): string
    {
        return match (true) {
            $this->resolved_at !== null => 'resolved',
            $this->acknowledged_at !== null => 'acknowledged',
            default => 'open',
        };
    }
}
