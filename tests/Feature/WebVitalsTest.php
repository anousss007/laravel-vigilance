<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Entry;
use Vigilance\Metrics\WebVitals;

uses(RefreshDatabase::class);

it('computes p75 web vitals per page with a good rating', function () {
    $key = (string) json_encode(['lcp', '/home']);

    $entries = new Collection;
    for ($i = 1; $i <= 100; $i++) {
        $entries->push((new Entry(time(), 'web_vital', $key, $i * 30))->count()->avg()->max());
    }
    app(Storage::class)->store($entries);

    $row = app(WebVitals::class)->forInterval(CarbonInterval::hours(24))->firstWhere('page', '/home');

    expect($row)->not->toBeNull()
        ->and($row->samples)->toBe(100)
        ->and($row->lcp)->toBe(2250) // p75 of 30..3000 (nearest rank)
        ->and($row->rating('lcp'))->toBe('good')
        ->and($row->inp)->toBeNull();
});

it('rates a slow page as poor', function () {
    $key = (string) json_encode(['lcp', '/slow']);

    $entries = new Collection;
    for ($i = 0; $i < 10; $i++) {
        $entries->push((new Entry(time(), 'web_vital', $key, 5000))->count()->avg()->max());
    }
    app(Storage::class)->store($entries);

    $row = app(WebVitals::class)->forInterval(CarbonInterval::hours(24))->firstWhere('page', '/slow');

    expect($row->rating('lcp'))->toBe('poor')
        ->and($row->overall())->toBe('poor');
});
