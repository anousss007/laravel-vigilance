<?php

namespace Vigilance\Apm\Recorders\Concerns;

trait Ignores
{
    /**
     * Whether $key matches any configured ignore regex.
     */
    protected function shouldIgnore(string $key): bool
    {
        foreach ((array) $this->recorderConfig('ignore', []) as $pattern) {
            if (@preg_match((string) $pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
