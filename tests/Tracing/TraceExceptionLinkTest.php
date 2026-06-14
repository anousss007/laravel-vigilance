<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Tracing\Contracts\TraceStorage;
use Vigilance\Tracing\Tracer;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

it('links a reported exception to the active trace as a span', function () {
    $tracer = app(Tracer::class);
    $t0 = microtime(true);

    $tracer->start('request', 'GET /checkout', $t0);
    Vigilance::report(new RuntimeException('gateway timeout'));
    $tracer->finish('error', $t0 + 2.0); // error => kept

    app(Apm::class)->ingest();

    $row = app(TraceStorage::class)->recent()->first();
    expect($row)->not->toBeNull();

    $trace = app(TraceStorage::class)->find($row->id);
    $types = collect($trace->spans)->pluck('type')->all();

    expect($types)->toContain('exception');
});
