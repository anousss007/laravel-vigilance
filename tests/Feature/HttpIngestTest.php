<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Entry;
use Vigilance\Apm\Ingests\HttpIngest;
use Vigilance\Apm\Value;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('vigilance.apm.ingest.http.endpoint', 'https://sink.test/ingest');
});

it('ships entries as json with their aggregations', function () {
    Http::fake();

    (new HttpIngest)->ingest(new Collection([
        (new Entry(time(), 'request', 'GET /x', 120))->count()->avg()->max(),
    ]));

    Http::assertSent(function ($request) {
        $entry = $request->data()['entries'][0];

        return $request->url() === 'https://sink.test/ingest'
            && $entry['kind'] === 'entry'
            && $entry['type'] === 'request'
            && $entry['value'] === 120
            // The receiver needs these to roll the feed up the way local
            // storage does; without them the numbers cannot be reconstructed.
            && $entry['aggregations'] === ['count', 'avg', 'max'];
    });
});

it('marks latest-wins snapshots as values', function () {
    Http::fake();

    (new HttpIngest)->ingest(new Collection([
        new Value(time(), 'system', 'web-1', '{"cpu":10}'),
    ]));

    Http::assertSent(fn ($request) => $request->data()['entries'][0]['kind'] === 'value');
});

it('labels the payload with where it came from', function () {
    Http::fake();
    config()->set('vigilance.release', 'v1.2.3');

    (new HttpIngest)->ingest(new Collection([new Entry(time(), 'request', 'GET /x', 1)]));

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['release'] === 'v1.2.3'
            && $body['environment'] === 'testing'
            && isset($body['sent_at']);
    });
});

it('splits a large flush into batches', function () {
    Http::fake();
    config()->set('vigilance.apm.ingest.http.batch', 2);

    $items = new Collection(array_map(
        fn (int $i) => new Entry(time(), 'request', 'GET /'.$i, $i),
        range(1, 5),
    ));

    (new HttpIngest)->ingest($items);

    Http::assertSentCount(3);
});

it('sends an auth header when a token is configured', function () {
    Http::fake();
    config()->set('vigilance.apm.ingest.http.token', 'secret');

    (new HttpIngest)->ingest(new Collection([new Entry(time(), 'request', 'GET /x', 1)]));

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret'));
});

it('does nothing without an endpoint', function () {
    Http::fake();
    config()->set('vigilance.apm.ingest.http.endpoint', null);

    (new HttpIngest)->ingest(new Collection([new Entry(time(), 'request', 'GET /x', 1)]));

    Http::assertNothingSent();
});

it('swallows a failing endpoint rather than breaking the flush', function () {
    Http::fake(fn () => throw new RuntimeException('sink is down'));

    (new HttpIngest)->ingest(new Collection([new Entry(time(), 'request', 'GET /x', 1)]));
})->throwsNoExceptions();

it('keeps writing locally when used as an exporter alongside storage', function () {
    // The whole point of the seam: an external sink is strictly additive.
    Http::fake(fn () => throw new RuntimeException('sink is down'));

    config()->set('vigilance.apm.ingest.exporters', ['http']);

    // Rebuild the container binding so the exporter list is picked up.
    app()->forgetInstance(Ingest::class);

    app(Apm::class)->record('request', 'GET /local', 100)->count();
    app(Apm::class)->ingest();

    expect(app(Storage::class)->aggregateTotal('request', 'count', CarbonInterval::hour()))
        ->toBeGreaterThan(0.0);
});

it('is resolvable as a driver by name', function () {
    config()->set('vigilance.apm.ingest.driver', 'http');
    app()->forgetInstance(Ingest::class);

    expect(app(Ingest::class))->toBeInstanceOf(HttpIngest::class);
});
