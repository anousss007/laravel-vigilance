<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Recorders\Requests;

uses(RefreshDatabase::class);

function fakeRequest(string $method, string $uri): Request
{
    $request = Request::create('/'.ltrim($uri, '/'), $method);
    $route = (new Route([$method], $uri, []))->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('records per-route throughput and a perfect Apdex for fast requests', function () {
    app(Requests::class)->record(new DateTimeImmutable, fakeRequest('GET', 'orders/{id}'), new Response('', 200));
    app(Apm::class)->ingest();

    $row = app(Storage::class)->aggregate('request', ['count', 'avg', 'max'], CarbonInterval::hour())->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->count)->toBe(1)
        ->and($row->key)->toContain('{id}');

    $apdex = app(Storage::class)->aggregate('request_apdex', ['avg'], CarbonInterval::hour())->first();

    expect((float) $apdex->avg)->toBe(100.0);
});

it('stores per-request duration as an entry value for percentile computation', function () {
    app(Requests::class)->record(new DateTimeImmutable, fakeRequest('GET', 'home'), new Response('', 200));
    app(Apm::class)->ingest();

    $value = DB::table('vigilance_entries')->where('type', 'request')->value('value');

    expect($value)->not->toBeNull();
});

it('counts 5xx responses as errors', function () {
    app(Requests::class)->record(new DateTimeImmutable, fakeRequest('GET', 'boom'), new Response('', 500));
    app(Apm::class)->ingest();

    $err = app(Storage::class)->aggregate('request_error', ['count'], CarbonInterval::hour())->first();

    expect((int) $err->count)->toBe(1);
});

it('ignores configured paths (the dashboard itself)', function () {
    app(Requests::class)->record(new DateTimeImmutable, fakeRequest('GET', 'vigilance/issues'), new Response('', 200));
    app(Apm::class)->ingest();

    expect(app(Storage::class)->aggregate('request', ['count'], CarbonInterval::hour()))->toBeEmpty();
});
