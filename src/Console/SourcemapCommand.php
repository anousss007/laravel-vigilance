<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Sourcemaps\SourceMapStore;
use Vigilance\Vigilance;

/**
 * Uploads JavaScript source maps so RUM browser errors can be symbolicated.
 * Run it from your deploy pipeline after the front-end build, e.g.
 *   php artisan vigilance:sourcemaps public/build --release=v1.4.0 --prune
 */
class SourcemapCommand extends Command
{
    protected $signature = 'vigilance:sourcemaps
        {path : A directory to scan for *.map files, or a single .map file}
        {--release= : The release these maps belong to (defaults to the current release)}
        {--prune : After uploading, delete source maps from every other release}';

    protected $description = 'Upload JavaScript source maps for symbolicating RUM browser errors.';

    public function handle(SourceMapStore $store): int
    {
        $release = (string) ($this->option('release') ?: Vigilance::currentRelease());

        if ($release === '') {
            $this->components->error('No release given and none configured. Pass --release or set vigilance.release / app.version.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $files = $this->resolveMaps($path);

        if ($files === []) {
            $this->components->warn("No .map files found at [{$path}].");

            return self::SUCCESS;
        }

        $stored = 0;
        foreach ($files as $file) {
            $content = (string) @file_get_contents($file);

            if ($content === '' || json_decode($content) === null) {
                $this->components->warn('Skipped (not valid JSON): '.basename($file));

                continue;
            }

            // "app-abc123.js.map" → key by the generated file "app-abc123.js".
            $generated = preg_replace('/\.map$/', '', basename($file));
            $store->put($release, (string) $generated, $content);
            $stored++;
        }

        $this->components->info("Stored {$stored} source map(s) for release [{$release}].");

        if ($this->option('prune')) {
            $removed = $store->pruneExcept($release);
            $this->components->info("Pruned {$removed} source map(s) from other releases.");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function resolveMaps(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, '.map') ? [$path] : [];
        }

        if (! is_dir($path)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && str_ends_with($entry->getFilename(), '.map')) {
                $found[] = $entry->getPathname();
            }
        }

        return $found;
    }
}
