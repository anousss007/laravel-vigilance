<?php

use Carbon\CarbonInterval;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Http\Livewire\Routes;
use Vigilance\Models\Suppression;
use Vigilance\Support\PathMatcher;
use Vigilance\Support\Suppressions;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Vigilance::auth(fn () => true);
    Suppressions::forget();
});

function suppress(string $scope, string $pattern, array $extra = []): Suppression
{
    $rule = Suppression::query()->create(array_merge([
        'scope' => $scope,
        'pattern' => $pattern,
        'action' => 'ignore',
    ], $extra));

    Suppressions::forget();

    return $rule;
}

it('mutes a route across every consumer of the ignore list', function () {
    // One entry point (PathMatcher) means APM, tracing, RUM and error capture
    // all honour it — not one of them with three separate implementations.
    expect(PathMatcher::ignored('/noisy'))->toBeFalse();

    suppress(Suppression::SCOPE_ROUTE, '/noisy');

    expect(PathMatcher::ignored('/noisy'))->toBeTrue()
        ->and(PathMatcher::ignored('/quiet'))->toBeFalse();
});

it('accepts a wildcard as well as a literal path', function () {
    suppress(Suppression::SCOPE_ROUTE, '/admin/*');

    expect(PathMatcher::ignored('/admin/users'))->toBeTrue()
        ->and(PathMatcher::ignored('/adminx'))->toBeFalse();
});

it('stops a muted query being recorded', function () {
    suppress(Suppression::SCOPE_QUERY, '#health_check#');

    Event::dispatch(new QueryExecuted('select * from health_check', [], 1500.0, DB::connection()));
    Event::dispatch(new QueryExecuted('select * from orders', [], 1500.0, DB::connection()));

    app(Apm::class)->ingest();

    $rows = app(Storage::class)->aggregate('slow_query', ['count'], CarbonInterval::hour());

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->key)->toContain('orders');
});

it('collapses a chatty cache key family instead of dropping it', function () {
    // Grouping keeps the signal but bounds the cardinality — the right answer
    // for "session:<uuid>" rather than throwing the data away.
    suppress(Suppression::SCOPE_CACHE_KEY, 'session:*', [
        'action' => 'group',
        'replacement' => 'session:*',
    ]);

    Event::dispatch(new CacheHit('array', 'session:abc', 'value'));
    Event::dispatch(new CacheHit('array', 'session:def', 'value'));

    app(Apm::class)->ingest();

    $rows = app(Storage::class)->aggregate('cache_hit', ['count'], CarbonInterval::hour());

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->key)->toBe('session:*');
});

it('stops applying a rule once it expires', function () {
    suppress(Suppression::SCOPE_ROUTE, '/temp', ['expires_at' => now()->subMinute()]);

    expect(PathMatcher::ignored('/temp'))->toBeFalse();
});

it('keeps a rule that has not expired yet', function () {
    suppress(Suppression::SCOPE_ROUTE, '/temp', ['expires_at' => now()->addHour()]);

    expect(PathMatcher::ignored('/temp'))->toBeTrue();
});

it('keeps scopes apart', function () {
    // A route pattern must not silently start matching SQL.
    suppress(Suppression::SCOPE_QUERY, '/orders');

    expect(PathMatcher::ignored('/orders'))->toBeFalse();
});

it('degrades to no rules when the table is unavailable', function () {
    // Monitoring must never break the app it observes, including before the
    // migration has run.
    Suppressions::forget();
    DB::statement('drop table vigilance_suppressions');

    expect(PathMatcher::ignored('/anything'))->toBeFalse();
});

it('creates and removes a rule from the Routes page', function () {
    Livewire::test(Routes::class)
        ->call('suppress', 'route', '/checkout');

    expect(PathMatcher::ignored('/checkout'))->toBeTrue();

    $rule = Suppression::query()->firstOrFail();

    Livewire::test(Routes::class)
        ->call('unsuppress', $rule->id);

    Suppressions::forget();

    expect(PathMatcher::ignored('/checkout'))->toBeFalse();
});

it('records an expiry when one was asked for', function () {
    Livewire::test(Routes::class)
        ->set('suppressFor', 30)
        ->call('suppress', 'route', '/checkout');

    expect(Suppression::query()->firstOrFail()->expires_at)->not->toBeNull();
});

it('refuses a scope it does not know', function () {
    Livewire::test(Routes::class)->call('suppress', 'nonsense', '/x');

    expect(Suppression::query()->count())->toBe(0);
});
