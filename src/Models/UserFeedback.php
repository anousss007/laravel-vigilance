<?php

namespace Vigilance\Models;

use Illuminate\Support\Carbon;

/**
 * A piece of user-reported feedback ("this page is slow", "the export is
 * broken") captured from the embedded widget — tied to the trace the user was
 * on so you can jump from the complaint to the technical telemetry.
 *
 * @property int $id
 * @property string $message
 * @property ?string $email
 * @property ?string $name
 * @property ?string $url
 * @property ?string $trace_id
 * @property ?string $user
 * @property ?Carbon $created_at
 */
class UserFeedback extends VigilanceModel
{
    protected $table = 'vigilance_user_feedback';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
