<?php

namespace Vigilance\Apm\Recorders\Concerns;

use Vigilance\Models\Suppression;
use Vigilance\Support\PathMatcher;
use Vigilance\Support\Suppressions;

trait Ignores
{
    use ScopesSuppressions;

    /**
     * Whether $key matches this recorder's ignore regexes, a rule created from
     * the dashboard, or the global vigilance.ignore_paths list (admin panels,
     * Livewire, …).
     */
    protected function shouldIgnore(string $key): bool
    {
        foreach ((array) $this->recorderConfig('ignore', []) as $pattern) {
            if (@preg_match((string) $pattern, $key) === 1) {
                return true;
            }
        }

        $scope = $this->suppressionScope();

        // Route-scope rules are applied by PathMatcher::ignored() below, which
        // is also what tracing, RUM and error capture go through — checking
        // them here as well would just cost a second pass.
        if ($scope !== Suppression::SCOPE_ROUTE && Suppressions::ignores($scope, $key)) {
            return true;
        }

        return PathMatcher::ignored($key);
    }
}
