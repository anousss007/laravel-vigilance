<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Ingests\StorageIngest;
use Vigilance\Tests\Fixtures\ExplodingIngest;
use Vigilance\Tests\Fixtures\SpyIngest;

uses(RefreshDatabase::class);

beforeEach(function () {
    SpyIngest::$ingested = 0;
    SpyIngest::$trimmed = false;
});

it('fans telemetry out to a registered exporter without losing the primary store', function () {
    config()->set('vigilance.apm.ingest.exporters', [SpyIngest::class]);
    app()->forgetInstance(Ingest::class);

    $apm = app(Apm::class);
    $apm->record('export_test', 'k', 1)->count();
    $apm->set('export_value', 'k', 'x');
    $apm->ingest();

    // Storage still received everything...
    expect(app(Storage::class)->aggregateTotal('export_test', 'count', CarbonInterval::hours(1)))->toBe(1.0)
        // ...and the exporter saw both the entry and the value.
        ->and(SpyIngest::$ingested)->toBe(2);
});

it('keeps storing when an exporter throws', function () {
    config()->set('vigilance.apm.ingest.exporters', [ExplodingIngest::class]);
    app()->forgetInstance(Ingest::class);

    $apm = app(Apm::class);
    $apm->record('export_test', 'k', 1)->count();
    $apm->ingest();

    expect(app(Storage::class)->aggregateTotal('export_test', 'count', CarbonInterval::hours(1)))->toBe(1.0);
});

it('uses the plain primary ingest when no exporters are configured', function () {
    config()->set('vigilance.apm.ingest.exporters', []);
    app()->forgetInstance(Ingest::class);

    expect(app(Ingest::class))->toBeInstanceOf(StorageIngest::class);
});
