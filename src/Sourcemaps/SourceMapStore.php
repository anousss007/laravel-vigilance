<?php

namespace Vigilance\Sourcemaps;

use Throwable;
use Vigilance\Models\SourceMapRecord;

/**
 * Stores uploaded source maps per release and symbolicates minified RUM stack
 * traces against them — rewriting "app-abc123.js:1:5000" into the original
 * "resources/js/checkout.js:42:9 (app-abc123.js:1:5000)" so browser errors in
 * the Issues inbox are actually debuggable.
 */
class SourceMapStore
{
    /** Store (or replace) the source map for a generated file in a release. */
    public function put(string $release, string $file, string $content): void
    {
        SourceMapRecord::query()->updateOrCreate(
            ['release' => $release, 'file' => $file],
            ['content' => $content, 'created_at' => now()],
        );
    }

    public function get(string $release, string $file): ?string
    {
        $content = SourceMapRecord::query()
            ->where('release', $release)
            ->where('file', $file)
            ->value('content');

        return $content !== null ? (string) $content : null;
    }

    /** Drop maps for every release except the given one (post-deploy cleanup). */
    public function pruneExcept(string $release): int
    {
        return (int) SourceMapRecord::query()->where('release', '!=', $release)->delete();
    }

    /**
     * Rewrite minified frames in a stack trace to their original source
     * locations. Returns null when no frame could be symbolicated (so the caller
     * keeps the raw stack rather than an identical copy).
     */
    public function symbolicate(?string $release, string $stack): ?string
    {
        if ($release === null || trim($stack) === '') {
            return null;
        }

        $maps = [];
        $symbolicated = false;

        $result = preg_replace_callback(
            "/((?:https?:\\/\\/)?[^\\s'\"()]+?\\.js)(?:\\?[^\\s'\"():]*)?:(\\d+)(?::(\\d+))?/",
            function (array $m) use ($release, &$maps, &$symbolicated): string {
                $line = (int) $m[2];
                $column = isset($m[3]) ? (int) $m[3] : 1;
                $file = basename($m[1]);

                if (! array_key_exists($file, $maps)) {
                    $content = $this->get($release, $file);
                    $maps[$file] = $content !== null ? SourceMap::fromJson($content) : null;
                }

                $map = $maps[$file];

                if ($map === null) {
                    return $m[0];
                }

                $original = $map->originalPositionFor($line, $column);

                if ($original === null) {
                    return $m[0];
                }

                $symbolicated = true;
                $name = $original['name'] !== null ? $original['name'].' @ ' : '';

                return $name.$original['source'].':'.$original['line'].':'.$original['column']." ({$m[0]})";
            },
            $stack,
        );

        return $symbolicated && is_string($result) ? $result : null;
    }

    /**
     * Symbolicate, swallowing any error — capture must never break on a bad map.
     */
    public function safeSymbolicate(?string $release, string $stack): ?string
    {
        try {
            return $this->symbolicate($release, $stack);
        } catch (Throwable) {
            return null;
        }
    }
}
