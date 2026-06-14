<?php

namespace Vigilance\Apm\Recorders\Concerns;

trait LocatesCode
{
    /**
     * The first application (non-vendor) frame in a backtrace, as "path:line".
     *
     * @param  list<array<string, mixed>>  $trace
     */
    protected function locationFromTrace(array $trace): string
    {
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && ! $this->isVendorFrame($file)) {
                return $this->cleanPath($file).':'.($frame['line'] ?? '?');
            }
        }

        return 'unknown';
    }

    protected function isVendorFrame(string $file): bool
    {
        return str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
    }

    protected function cleanPath(string $file): string
    {
        $base = function_exists('base_path') ? base_path() : '';

        return $base !== '' ? str_replace($base.DIRECTORY_SEPARATOR, '', $file) : $file;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function captureTrace(int $limit = 25): array
    {
        return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
    }
}
