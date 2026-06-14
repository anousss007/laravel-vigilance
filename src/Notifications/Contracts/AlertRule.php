<?php

namespace Vigilance\Notifications\Contracts;

use Vigilance\Notifications\Alert;

/**
 * A rule the AlertManager evaluates each cycle. Return zero or more Alerts; an
 * empty return means "nothing wrong right now".
 */
interface AlertRule
{
    /**
     * @return iterable<Alert>
     */
    public function evaluate(): iterable;
}
