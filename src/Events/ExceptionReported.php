<?php

namespace Vigilance\Events;

use Throwable;

/**
 * Dispatched by Vigilance::report() so applications can surface caught /
 * swallowed exceptions to the APM exception card even when they never reach the
 * framework's reportable() hook.
 */
class ExceptionReported
{
    public function __construct(public Throwable $exception) {}
}
