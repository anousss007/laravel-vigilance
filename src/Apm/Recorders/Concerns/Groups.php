<?php

namespace Vigilance\Apm\Recorders\Concerns;

use Vigilance\Support\Suppressions;

trait Groups
{
    use ScopesSuppressions;

    /**
     * Collapse high-cardinality keys via configured [regex => replacement] rules
     * (e.g. /users/123 → /users/*), to keep key cardinality bounded. Rules
     * created from the dashboard are applied first, so collapsing a key you have
     * just watched explode does not require a config edit and a deploy.
     */
    protected function group(string $value): string
    {
        $grouped = Suppressions::group($this->suppressionScope(), $value);

        if ($grouped !== $value) {
            return $grouped;
        }

        foreach ((array) $this->recorderConfig('groups', []) as $pattern => $replacement) {
            $result = @preg_replace((string) $pattern, (string) $replacement, $value, -1, $count);

            if ($count > 0 && $result !== null) {
                return $result;
            }
        }

        return $value;
    }
}
