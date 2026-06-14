<?php

namespace Vigilance\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $scope_type
 * @property string $scope
 * @property int $throughput
 * @property int $failures
 * @property int $runtime_avg_ms
 * @property ?int $wait_avg_ms
 * @property ?Carbon $measured_at
 */
class MetricSnapshot extends VigilanceModel
{
    protected $table = 'vigilance_metric_snapshots';

    public $timestamps = false;

    protected $casts = [
        'throughput' => 'integer',
        'failures' => 'integer',
        'runtime_avg_ms' => 'integer',
        'wait_avg_ms' => 'integer',
        'measured_at' => 'datetime',
    ];
}
