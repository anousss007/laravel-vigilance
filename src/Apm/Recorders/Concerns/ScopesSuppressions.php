<?php

namespace Vigilance\Apm\Recorders\Concerns;

use Vigilance\Models\Suppression;

/**
 * Which family of dashboard-created rules applies to a recorder's keys.
 *
 * Lives in its own trait because both Ignores and Groups need it, and a class
 * composing both would otherwise hit a trait method collision. Composed from a
 * single origin, PHP flattens it cleanly.
 */
trait ScopesSuppressions
{
    /** Recorders keyed by something other than a request path override this. */
    protected function suppressionScope(): string
    {
        return Suppression::SCOPE_ROUTE;
    }
}
