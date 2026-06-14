<?php

namespace Vigilance\Models;

use Illuminate\Support\Carbon;

/**
 * Heartbeat record for a single worker process owned by a supervisor.
 *
 * @property int $id
 * @property string $supervisor
 * @property ?string $host
 * @property ?int $pid
 * @property ?string $connection
 * @property ?string $queue
 * @property string $status
 * @property ?Carbon $last_heartbeat_at
 */
class WorkerRecord extends VigilanceModel
{
    protected $table = 'vigilance_workers';

    protected $casts = [
        'pid' => 'integer',
        'last_heartbeat_at' => 'datetime',
    ];
}
