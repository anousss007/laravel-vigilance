<?php

namespace Vigilance\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ?string $user
 * @property string $action
 * @property ?string $subject
 * @property ?int $run_id
 * @property ?array<string, mixed> $meta
 * @property ?Carbon $created_at
 */
class AuditEntry extends VigilanceModel
{
    protected $table = 'vigilance_audit';

    public $timestamps = false;

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];
}
