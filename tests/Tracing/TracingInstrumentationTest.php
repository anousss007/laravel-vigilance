<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Vigilance\Tests\Fixtures\SampleJob;
use Vigilance\Tracing\Contracts\TraceStorage;

uses(RefreshDatabase::class);

it('traces an HTTP request end-to-end with query and cache spans', function () {
    Route::get('/_trace_probe', function () {
        DB::table('vigilance_traces')->count(); // a real query span
        Cache::get('missing-key');               // a cache span
        Cache::put('warm', 1, 60);
        Cache::get('warm');

        return 'ok';
    });

    $this->get('/_trace_probe')->assertOk();

    $traces = app(TraceStorage::class)->recent();
    expect($traces)->toHaveCount(1);

    $row = $traces->first();
    expect($row->type)->toBe('request')
        ->and($row->name)->toBe('GET /_trace_probe')
        ->and($row->status)->toBe('ok');

    $trace = app(TraceStorage::class)->find($row->id);
    $types = collect($trace->spans)->pluck('type')->unique()->values()->all();

    expect($trace->spanCount)->toBeGreaterThan(0)
        ->and($types)->toContain('query')
        ->and($types)->toContain('cache');
});

it('marks a 500 response trace as errored', function () {
    Route::get('/_trace_error', function () {
        abort(500, 'boom');
    });

    $this->get('/_trace_error');

    $row = app(TraceStorage::class)->recent()->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('error');
});

it('does not trace ignored dashboard paths', function () {
    // The dashboard prefix is in the tracing ignore list.
    $this->get('/vigilance');

    expect(app(TraceStorage::class)->recent()->where('name', 'GET /vigilance'))->toHaveCount(0);
});

it('traces a queued job as its own root trace', function () {
    SampleJob::dispatch(3, 'hello');

    $jobTraces = app(TraceStorage::class)->recent(['type' => 'job']);

    expect($jobTraces)->toHaveCount(1)
        ->and($jobTraces->first()->name)->toContain('SampleJob');
});
