<?php

use Illuminate\Support\Arr;

/**
 * Every `config('vigilance.…')` the package reads must be a key the package
 * ships.
 *
 * A misspelled key does not fail — it silently returns the caller's default, so
 * the code keeps running against a value nobody configured. That is how the
 * Usage page came to measure retention against a phantom `vigilance.retention_days`
 * (real key: `vigilance.retention.days`) and then told operators their scheduler
 * was broken. Nothing in a test suite catches that, because the wrong reading is
 * still a perfectly valid one.
 */
/**
 * Keys deliberately absent from the shipped array, because "not set" is itself
 * the meaningful state — the code derives the value from somewhere else. Each
 * one needs a reason here; that is the cost of not being discoverable in the
 * config file, and it keeps this list from becoming a place to hide typos.
 */
const OPTIONAL_OVERRIDES = [
    // Unset = inherit alerts.throttle_minutes, so the notify window and the
    // throttle window cannot drift apart by accident.
    'alerts.rules.new_issue.window_minutes',
    'alerts.rules.issue_regression.window_minutes',
    // Unset = the rule's built-in metric set (latency, 5xx, exceptions). Shipped
    // as a commented example in the config rather than as a value.
    'alerts.rules.anomaly.metrics',
];

it('reads no configuration key it does not ship', function () {
    $shipped = require __DIR__.'/../../config/vigilance.php';

    $files = collect(
        iterator_to_array(
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(realpath(__DIR__.'/../../src'), RecursiveDirectoryIterator::SKIP_DOTS)
            )
        )
    )->filter(fn ($f) => $f->isFile() && $f->getExtension() === 'php')
        ->merge(
            collect(glob(realpath(__DIR__.'/../../resources/views').'/{,*/,*/*/}*.blade.php', GLOB_BRACE) ?: [])
                ->map(fn ($p) => new SplFileInfo($p))
        );

    $missing = [];

    foreach ($files as $file) {
        $source = file_get_contents((string) $file->getRealPath());

        // Only fully-literal keys: an interpolated one cannot be checked here.
        preg_match_all("/config\(\s*'vigilance\.([A-Za-z0-9_.]+)'/", $source, $matches);

        foreach ($matches[1] as $key) {
            if (Arr::has($shipped, $key) || in_array($key, OPTIONAL_OVERRIDES, true)) {
                continue;
            }

            $missing[] = $key.'  ('.basename((string) $file->getRealPath()).')';
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
