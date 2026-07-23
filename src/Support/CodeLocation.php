<?php

namespace Vigilance\Support;

/**
 * Resolves the first application (non-vendor) frame that led to a call — used to
 * point a query span (and the N+1 signal built from it) at the exact line of app
 * code that ran the query, so you don't have to hunt for it.
 */
class CodeLocation
{
    /**
     * The nearest application frame as "path:line" (relative to the app root),
     * or null when the whole stack is framework/vendor code. Args are ignored
     * and the walk is bounded, so it's cheap enough for a sampled trace.
     */
    public static function caller(int $limit = 30): ?string
    {
        // The Vigilance package's own directory, so its frames are skipped even
        // when it isn't installed under vendor/ (a symlinked/path-repo install).
        $packageDir = \dirname(__DIR__, 2).DIRECTORY_SEPARATOR;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit) as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || $file === '') {
                continue;
            }

            // Skip framework/dependency frames, Vigilance's own frames (by path or
            // namespace), and keep walking until we reach application code.
            if (str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
                || str_starts_with($file, $packageDir)
                || str_starts_with((string) ($frame['class'] ?? ''), 'Vigilance\\')) {
                continue;
            }

            return static::relative($file).':'.($frame['line'] ?? '?');
        }

        return null;
    }

    protected static function relative(string $file): string
    {
        $base = function_exists('base_path') ? base_path() : '';

        return $base !== '' ? str_replace($base.DIRECTORY_SEPARATOR, '', $file) : $file;
    }
}
