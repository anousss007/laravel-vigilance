<?php

namespace Vigilance\Models;

use Illuminate\Support\Carbon;

/**
 * Heartbeat record for a running supervisor process.
 *
 * @property string $name
 * @property ?string $master
 * @property ?string $host
 * @property ?int $pid
 * @property string $status
 * @property ?string $connection
 * @property ?string $queues
 * @property ?string $balance
 * @property int $processes
 * @property ?array<string, int> $pools
 * @property ?array<string, mixed> $options
 * @property ?Carbon $last_heartbeat_at
 */
class SupervisorRecord extends VigilanceModel
{
    protected $table = 'vigilance_supervisors';

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'pid' => 'integer',
        'processes' => 'integer',
        'pools' => 'array',
        'options' => 'array',
        'last_heartbeat_at' => 'datetime',
    ];
}
