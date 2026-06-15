<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Metrics\CustomMetrics;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

it('records and summarises custom counter and gauge metrics', function () {
    Vigilance::increment('signups', 3);
    Vigilance::increment('signups'); // +1 -> total 4 over 2 events
    Vigilance::gauge('cart_value', 100);
    Vigilance::gauge('cart_value', 200); // avg 150, max 200

    app(Apm::class)->ingest();

    $metrics = app(CustomMetrics::class)->all(CarbonInterval::hour());

    $signups = $metrics->firstWhere('name', 'signups');
    $cart = $metrics->firstWhere('name', 'cart_value');

    expect($signups)->not->toBeNull()
        ->and($signups->type)->toBe('count')
        ->and($signups->value)->toBe(4)
        ->and($signups->peak)->toBe(2);

    expect($cart)->not->toBeNull()
        ->and($cart->type)->toBe('value')
        ->and($cart->value)->toBe(150)
        ->and($cart->peak)->toBe(200);
});

it('returns nothing when no custom metrics were recorded', function () {
    expect(app(CustomMetrics::class)->all(CarbonInterval::hour()))->toBeEmpty();
});
