<?php

namespace Vigilance\Apm\Recorders\Concerns;

trait Thresholds
{
    /**
     * Whether a duration (ms) is below the configured threshold and should be
     * dropped as "not slow". The threshold may be a flat int or a
     * [regex => ms, 'default' => ms] map matched against $key.
     */
    protected function underThreshold(int $duration, string $key = 'default'): bool
    {
        return $duration < $this->threshold($key);
    }

    protected function threshold(string $key): int
    {
        $threshold = $this->recorderConfig('threshold', 1000);

        if (is_array($threshold)) {
            foreach ($threshold as $pattern => $ms) {
                if ($pattern !== 'default' && @preg_match($pattern, $key) === 1) {
                    return (int) $ms;
                }
            }

            return (int) ($threshold['default'] ?? 1000);
        }

        return (int) $threshold;
    }
}
