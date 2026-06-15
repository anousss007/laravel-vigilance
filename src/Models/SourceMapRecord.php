<?php

namespace Vigilance\Models;

use Illuminate\Support\Carbon;

/**
 * A stored Source Map for one generated file in one release, used to symbolicate
 * minified RUM stack traces.
 *
 * @property int $id
 * @property string $release
 * @property string $file
 * @property string $content
 * @property ?Carbon $created_at
 */
class SourceMapRecord extends VigilanceModel
{
    protected $table = 'vigilance_sourcemaps';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
