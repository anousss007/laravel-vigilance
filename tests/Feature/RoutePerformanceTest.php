<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Entry;
use Vigilance\Metrics\RoutePerformance;

uses(RefreshDatabase::class);

it('computes exact per-route latency percentiles from request durations', function () {
    $key = (string) json_encode(['GET', '/orders']);

    $entries = new Collection;
    for ($i = 1; $i <= 100; $i++) {
        $entries->push((new Entry(time(), 'request', $key, $i))->count()->avg()->max());
    }
    app(Storage::class)->store($entries);

    $row = app(RoutePerformance::class)->forInterval(CarbonInterval::hour())->firstWhere('path', '/orders');

    expect($row)->not->toBeNull()
        ->and($row->count)->toBe(100)
        ->and($row->method)->toBe('GET')
        ->and($row->p50)->toBe(50)
        ->and($row->p95)->toBe(95)
        ->and($row->p99)->toBe(99)
        ->and($row->max)->toBe(100)
        ->and($row->error_rate)->toBe(0.0);
});

it('computes error rate from 5xx counts', function () {
    $key = (string) json_encode(['POST', '/pay']);

    $entries = new Collection;
    for ($i = 0; $i < 10; $i++) {
        $entries->push((new Entry(time(), 'request', $key, 120))->count()->avg()->max());
    }
    for ($i = 0; $i < 2; $i++) {
        $entries->push((new Entry(time(), 'request_error', $key, 500))->count());
    }
    app(Storage::class)->store($entries);

    $row = app(RoutePerformance::class)->forInterval(CarbonInterval::hour())->firstWhere('path', '/pay');

    expect($row->count)->toBe(10)
        ->and($row->errors)->toBe(2)
        ->and($row->error_rate)->toBe(20.0);
});

it('derives apdex from the recorded score (avg / 100)', function () {
    $key = (string) json_encode(['GET', '/fast']);

    $entries = new Collection;
    $entries->push((new Entry(time(), 'request', $key, 50))->count()->avg()->max());
    $entries->push((new Entry(time(), 'request_apdex', $key, 100))->avg());
    app(Storage::class)->store($entries);

    $row = app(RoutePerformance::class)->forInterval(CarbonInterval::hour())->firstWhere('path', '/fast');

    expect($row->apdex)->toBe(1.0);
});
