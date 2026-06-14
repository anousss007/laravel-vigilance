<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;

uses(RefreshDatabase::class);

it('buffers telemetry and flushes it through the ingest into storage', function () {
    $apm = app(Apm::class);

    $apm->record('test_metric', 'key-1', 150)->max()->count();
    $apm->record('test_metric', 'key-1', 350)->max()->count();
    $apm->set('test_value', 'snapshot', 'hello');

    $apm->ingest();

    $rows = app(Storage::class)->aggregate('test_metric', ['max', 'count'], CarbonInterval::hours(1));

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->max)->toBe(350)
        ->and((int) $rows->first()->count)->toBe(2);

    expect(app(Storage::class)->values('test_value')->first()->value)->toBe('hello');
});

it('does not record while recording is stopped', function () {
    $apm = app(Apm::class);

    $apm->stopRecording();
    $apm->record('test_metric', 'k', 1)->count();
    $apm->startRecording();
    $apm->ingest();

    expect(app(Storage::class)->aggregateTotal('test_metric', 'count', CarbonInterval::hours(1)))->toBe(0.0);
});

it('resolves lazy closures at flush time', function () {
    $apm = app(Apm::class);

    $apm->lazy(fn () => $apm->record('lazy_metric', 'k', 1)->count());

    expect(app(Storage::class)->aggregateTotal('lazy_metric', 'count', CarbonInterval::hours(1)))->toBe(0.0);

    $apm->ingest();

    expect(app(Storage::class)->aggregateTotal('lazy_metric', 'count', CarbonInterval::hours(1)))->toBe(1.0);
});

it('honours filters', function () {
    $apm = app(Apm::class);

    $apm->filter(fn ($entry) => $entry->type !== 'blocked_metric');
    $apm->record('blocked_metric', 'k', 1)->count();
    $apm->record('allowed_metric', 'k', 1)->count();
    $apm->ingest();

    expect(app(Storage::class)->aggregateTotal('blocked_metric', 'count', CarbonInterval::hours(1)))->toBe(0.0)
        ->and(app(Storage::class)->aggregateTotal('allowed_metric', 'count', CarbonInterval::hours(1)))->toBe(1.0);
});
