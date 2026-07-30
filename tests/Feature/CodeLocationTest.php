<?php

use Vigilance\Support\CodeLocation;

it('never resolves to a Vigilance-internal or vendor frame', function () {
    // In the package's own test suite every frame lives under the package
    // directory or vendor, so caller() correctly resolves to no application
    // frame (null) rather than pointing at Vigilance's own internals — the exact
    // regression guard for the N+1 "caller" location. The positive path (a real
    // app frame → "relative/path.php:line") is covered end-to-end against a live
    // Laravel app.
    expect(CodeLocation::caller())->toBeNull();
});

it('promotes the first application frame out of a stored exception trace', function () {
    // A trace as captured on an exception: framework frames on top (where the
    // fault surfaced), the offending application frame below. fromTrace() walks
    // past vendor to the app frame — the line you actually fix.
    $trace = [
        ['file' => '/srv/app/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php', 'line' => 356, 'class' => 'Illuminate\\Database\\Eloquent\\Builder'],
        ['file' => '/srv/app/vendor/filament/tables/src/Concerns/CanSummarizeRecords.php', 'line' => 120],
        ['file' => '/srv/app/app/Filament/Resources/CarResource/RelationManagers/PhotosRelationManager.php', 'line' => 42],
        ['file' => '/srv/app/public/index.php', 'line' => 17],
    ];

    expect(CodeLocation::fromTrace($trace))
        ->toContain('PhotosRelationManager.php:42')
        ->not->toContain('Builder.php');
});

it('returns null from a trace that is entirely vendor code', function () {
    expect(CodeLocation::fromTrace([
        ['file' => '/srv/app/vendor/laravel/framework/src/Foundation/Application.php', 'line' => 1],
    ]))->toBeNull();
});
