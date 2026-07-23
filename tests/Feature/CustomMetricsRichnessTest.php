<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Metrics\CustomMetrics;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

it('records a distribution metric with percentiles', function () {
    foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 1000] as $ms) {
        Vigilance::timing('checkout_ms', $ms);
    }
    app(Apm::class)->ingest();

    $dist = app(CustomMetrics::class)->all(CarbonInterval::hour())->firstWhere('name', 'checkout_ms');

    expect($dist)->not->toBeNull()
        ->and($dist->type)->toBe('distribution')
        ->and($dist->p50)->not->toBeNull()
        ->and($dist->p95)->toBeGreaterThanOrEqual($dist->p50)
        ->and($dist->p99)->toBeGreaterThanOrEqual($dist->p95)
        ->and($dist->peak)->toBe(1000); // the outlier is the max
});

it('decrements a counter through the same series', function () {
    Vigilance::increment('active_sessions', 5);
    Vigilance::decrement('active_sessions', 2);
    app(Apm::class)->ingest();

    $counter = app(CustomMetrics::class)->all(CarbonInterval::hour())->firstWhere('name', 'active_sessions');

    expect($counter)->not->toBeNull()
        ->and($counter->type)->toBe('count')
        ->and($counter->value)->toBe(3);
});
