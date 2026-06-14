<?php

namespace Vigilance\Models;

use Illuminate\Support\Carbon;

/**
 * A tag the user has opted to watch (pinned on the Tags page).
 *
 * @property string $tag
 * @property ?Carbon $created_at
 */
class MonitoredTag extends VigilanceModel
{
    protected $table = 'vigilance_monitored_tags';

    protected $primaryKey = 'tag';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
