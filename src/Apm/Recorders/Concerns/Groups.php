<?php

namespace Vigilance\Apm\Recorders\Concerns;

trait Groups
{
    /**
     * Collapse high-cardinality keys via configured [regex => replacement] rules
     * (e.g. /users/123 → /users/*), to keep key cardinality bounded.
     */
    protected function group(string $value): string
    {
        foreach ((array) $this->recorderConfig('groups', []) as $pattern => $replacement) {
            $grouped = @preg_replace((string) $pattern, (string) $replacement, $value, -1, $count);

            if ($count > 0 && $grouped !== null) {
                return $grouped;
            }
        }

        return $value;
    }
}
