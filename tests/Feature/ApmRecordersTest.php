<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Events\SharedBeat;
use Vigilance\Apm\Recorders\SlowOutgoingRequests;
use Vigilance\Apm\Recorders\SlowRequests;
use Vigilance\Apm\Recorders\UserRequests;

uses(RefreshDatabase::class);

function apmCount(string $type): float
{
    return app(Storage::class)->aggregateTotal($type, 'count', CarbonInterval::hours(1));
}

it('records a slow query over the threshold, ignoring fast ones', function () {
    Event::dispatch(new QueryExecuted('select * from users where id = ?', [1], 1500.0, DB::connection()));
    Event::dispatch(new QueryExecuted('select 1', [], 4.0, DB::connection()));

    app(Apm::class)->ingest();

    expect(apmCount('slow_query'))->toBe(1.0);

    $rows = app(Storage::class)->aggregate('slow_query', ['max', 'count'], CarbonInterval::hours(1));
    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->max)->toBe(1500)
        ->and(json_decode($rows->first()->key, true)['sql'])->toBe('select * from users where id = ?');
});

it('records cache hits and misses by grouped key', function () {
    Event::dispatch(new CacheMissed('array', 'profile:7'));
    Event::dispatch(new CacheHit('array', 'profile:7', 'value'));
    Event::dispatch(new CacheHit('array', 'profile:9', 'value'));

    app(Apm::class)->ingest();

    expect(apmCount('cache_miss'))->toBe(1.0)
        ->and(apmCount('cache_hit'))->toBe(2.0);
});

it('does not record cache traffic for its own ignored keys', function () {
    Event::dispatch(new CacheHit('array', 'vigilance:apm:server:web-1', true));

    app(Apm::class)->ingest();

    expect(apmCount('cache_hit'))->toBe(0.0);
});

it('records a reported exception keyed by class and location', function () {
    app(ExceptionHandler::class)->report(new RuntimeException('boom'));

    app(Apm::class)->ingest();

    expect(apmCount('exception'))->toBe(1.0);

    $rows = app(Storage::class)->aggregate('exception', ['count'], CarbonInterval::hours(1));
    expect(json_decode($rows->first()->key, true)['class'])->toBe(RuntimeException::class);
});

it('records a slow request keyed by method and matched route uri', function () {
    $recorder = app(SlowRequests::class);

    $request = Request::create('/users/42', 'GET');
    $request->setRouteResolver(fn () => (new Route('GET', '/users/{user}', []))->bind($request));

    // Started 2s ago => ~2000ms, comfortably over the 1000ms threshold.
    $recorder->record(CarbonImmutable::now()->subSeconds(2), $request, new Response('ok'));

    app(Apm::class)->ingest();

    $rows = app(Storage::class)->aggregate('slow_request', ['max', 'count'], CarbonInterval::hours(1));

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->key)->toBe(json_encode(['GET', '/users/{user}']))
        ->and((int) $rows->first()->max)->toBeGreaterThanOrEqual(1000);
});

it('attributes a slow Livewire update to the referring page', function () {
    $recorder = app(SlowRequests::class);

    $request = Request::create('/livewire/update', 'POST');
    $request->setRouteResolver(fn () => (new Route('POST', 'livewire/update', []))->bind($request));
    $request->headers->set('referer', 'http://localhost/orders/42');

    $recorder->record(CarbonImmutable::now()->subSeconds(2), $request, new Response('ok'));

    app(Apm::class)->ingest();

    $rows = app(Storage::class)->aggregate('slow_request', ['count'], CarbonInterval::hours(1));
    expect($rows->first()->key)->toBe(json_encode(['POST', '/orders/42 (livewire)']));
});

it('does not record a fast request under the threshold', function () {
    $recorder = app(SlowRequests::class);

    $request = Request::create('/health', 'GET');
    $request->setRouteResolver(fn () => (new Route('GET', '/health', []))->bind($request));

    $recorder->record(CarbonImmutable::now(), $request, new Response('ok'));

    app(Apm::class)->ingest();

    expect(apmCount('slow_request'))->toBe(0.0);
});

it('records a slow outgoing request keyed by method and grouped uri', function () {
    $recorder = app(SlowOutgoingRequests::class);

    $startedAtMs = (int) round(microtime(true) * 1000) - 2000;
    $recorder->record(new PsrRequest('GET', 'https://api.example.com/slow'), $startedAtMs);

    app(Apm::class)->ingest();

    expect(apmCount('slow_outgoing_request'))->toBe(1.0);

    $rows = app(Storage::class)->aggregate('slow_outgoing_request', ['count'], CarbonInterval::hours(1));
    expect(json_decode($rows->first()->key, true)[0])->toBe('GET');
});

it('attributes requests to the authenticated user', function () {
    app(Apm::class)->resolveUserUsing(fn () => ['id' => 7, 'name' => 'Alice', 'extra' => 'alice@example.com']);

    $recorder = app(UserRequests::class);

    $request = Request::create('/dashboard', 'GET');
    $request->setRouteResolver(fn () => (new Route('GET', '/dashboard', []))->bind($request));

    $recorder->record(CarbonImmutable::now(), $request, new Response('ok'));
    $recorder->record(CarbonImmutable::now(), $request, new Response('ok'));

    app(Apm::class)->ingest();

    expect(app(Storage::class)->aggregateTotal('user_request', 'count', CarbonInterval::hours(1)))->toBe(2.0);

    $user = app(Storage::class)->values('user')->get('7');
    expect($user)->not->toBeNull()
        ->and(json_decode($user->value, true)['name'])->toBe('Alice');
});

it('attributes a slow request to the authenticated user', function () {
    app(Apm::class)->resolveUserUsing(fn () => ['id' => 9, 'name' => 'Bob', 'extra' => '']);

    $recorder = app(SlowRequests::class);

    $request = Request::create('/reports', 'GET');
    $request->setRouteResolver(fn () => (new Route('GET', '/reports', []))->bind($request));

    $recorder->record(CarbonImmutable::now()->subSeconds(2), $request, new Response('ok'));

    app(Apm::class)->ingest();

    expect(app(Storage::class)->aggregateTotal('slow_user_request', 'count', CarbonInterval::hours(1)))->toBe(1.0)
        ->and(app(Storage::class)->aggregateTotal('slow_request', 'count', CarbonInterval::hours(1)))->toBe(1.0);
});

it('records server cpu, memory and a system snapshot on a beat', function () {
    Event::dispatch(new SharedBeat(CarbonImmutable::now(), 'test-server'));

    app(Apm::class)->ingest();

    $system = app(Storage::class)->values('system');
    expect($system)->not->toBeEmpty();

    $snapshot = json_decode($system->first()->value, true);
    expect($snapshot)->toHaveKeys(['name', 'cpu', 'memory_used', 'memory_total', 'storage']);

    expect(app(Storage::class)->aggregate('memory', 'avg', CarbonInterval::hours(1)))->toHaveCount(1)
        ->and(app(Storage::class)->aggregate('cpu', 'avg', CarbonInterval::hours(1)))->toHaveCount(1);
});
