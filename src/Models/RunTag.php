<?php

namespace Vigilance\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $run_id
 * @property string $tag
 * @property ?Carbon $created_at
 */
class RunTag extends VigilanceModel
{
    protected $table = 'vigilance_run_tags';

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
