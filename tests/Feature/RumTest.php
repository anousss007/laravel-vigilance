<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Capture\FailureGrouper;
use Vigilance\Http\Controllers\RumController;
use Vigilance\Models\FailureGroup;

uses(RefreshDatabase::class);

function callRum(array $payload): void
{
    $request = Request::create('/vigilance/rum', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));

    app(RumController::class)->store($request, app(Apm::class), app(FailureGrouper::class));
}

it('records web vitals keyed by metric and page when enabled', function () {
    config()->set('vigilance.rum.enabled', true);

    callRum(['page' => '/checkout?ref=x', 'metrics' => [['name' => 'lcp', 'value' => 2400], ['name' => 'cls', 'value' => 12]]]);
    app(Apm::class)->ingest();

    $vitals = app(Storage::class)->aggregate('web_vital', ['count', 'max'], CarbonInterval::hour());

    expect($vitals)->toHaveCount(2)
        ->and($vitals->pluck('key')->implode('|'))->toContain('lcp')->toContain('/checkout');
});

it('routes browser errors into the issues inbox as source browser', function () {
    config()->set('vigilance.rum.enabled', true);

    callRum(['page' => '/app', 'errors' => [['message' => 'TypeError: x is undefined', 'source' => 'app.js:10', 'stack' => 'at foo()']]]);

    $issue = FailureGroup::query()->where('source', 'browser')->first();

    expect($issue)->not->toBeNull()
        ->and($issue->message)->toContain('TypeError')
        ->and($issue->name)->toBe('/app')
        ->and($issue->sample)->toContain('foo');
});

it('404s when RUM is disabled', function () {
    config()->set('vigilance.rum.enabled', false);

    callRum(['page' => '/x', 'metrics' => [['name' => 'lcp', 'value' => 100]]]);
})->throws(NotFoundHttpException::class);

it('ignores unknown metric names and out-of-range values', function () {
    config()->set('vigilance.rum.enabled', true);

    callRum(['page' => '/x', 'metrics' => [['name' => 'bogus', 'value' => 1], ['name' => 'lcp', 'value' => 99999999]]]);
    app(Apm::class)->ingest();

    expect(app(Storage::class)->aggregate('web_vital', ['count'], CarbonInterval::hour()))->toBeEmpty();
});
