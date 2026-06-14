<?php

namespace Vigilance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Vigilance\Models\FailureGroup;

/**
 * Fired whenever a job or command failure is recorded. Listen for this to wire
 * your own alerting (Slack, mail, PagerDuty…). `$isNew` is true the first time
 * a given failure signature is seen, so you can alert only on new error types.
 */
class FailureRecorded
{
    use Dispatchable;

    public function __construct(
        public FailureGroup $group,
        public bool $isNew,
    ) {}
}
