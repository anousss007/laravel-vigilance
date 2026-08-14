<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route as RouteFacade;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Recorders\RequestProfile;
use Vigilance\Http\Livewire\Routes;

uses(RefreshDatabase::class);

/** A non-Vigilance Eloquent model over a table that always has rows. */
class ProbeMigration extends Model
{
    protected $table = 'migrations';

    public $timestamps = false;
}

beforeEach(function () {
    // Migrations and test setup run queries of their own before the example
    // body; start every test from a clean slate.
    app(RequestProfile::class)->flush();
});

function profileRequest(string $uri = '/users/{user}', string $method = 'GET', string $path = '/users/42'): void
{
    $request = Request::create($path, $method);
    $request->setRouteResolver(fn () => (new Route($method, $uri, []))->bind($request));

    app(RequestProfile::class)->record(CarbonImmutable::now(), $request, new Response('ok'));
    app(Apm::class)->ingest();
}

function profileRow(string $type): ?object
{
    return app(Storage::class)->aggregate($type, ['count', 'avg', 'max'], CarbonInterval::hours(1))->first();
}

it('records the query count and database time of a request, keyed by route', function () {
    Event::dispatch(new QueryExecuted('select * from users where id = ?', [1], 4.0, DB::connection()));
    Event::dispatch(new QueryExecuted('select * from posts where user_id = ?', [1], 6.5, DB::connection()));

    profileRequest();

    $queries = profileRow('request_queries');
    $dbMs = profileRow('request_db_ms');

    expect($queries->key)->toBe(json_encode(['GET', '/users/{user}']))
        ->and((int) $queries->max)->toBe(2)
        ->and((int) $dbMs->max)->toBe(11); // 4.0 + 6.5, rounded
});

it('does not count queries against its own tables', function () {
    Event::dispatch(new QueryExecuted('select * from vigilance_entries', [], 3.0, DB::connection()));
    Event::dispatch(new QueryExecuted('select * from users', [], 1.0, DB::connection()));

    profileRequest();

    expect((int) profileRow('request_queries')->max)->toBe(1);
});

it('records peak memory for the request', function () {
    profileRequest();

    expect((int) profileRow('request_memory')->max)->toBeGreaterThan(0);
});

it('counts hydrated Eloquent models but not its own', function () {
    Event::dispatch(new QueryExecuted('select * from users', [], 1.0, DB::connection()));
    Event::dispatch('eloquent.retrieved: App\Models\User', [new stdClass]);
    Event::dispatch('eloquent.retrieved: App\Models\User', [new stdClass]);
    Event::dispatch('eloquent.retrieved: Vigilance\Models\JobRun', [new stdClass]);

    profileRequest();

    expect((int) profileRow('request_models')->max)->toBe(2);
});

it('skips the database metrics for a route that ran no queries', function () {
    profileRequest();

    expect(profileRow('request_queries'))->not->toBeNull()
        ->and((int) profileRow('request_queries')->max)->toBe(0)
        ->and(profileRow('request_db_ms'))->toBeNull()
        ->and(profileRow('request_models'))->toBeNull();
});

it('resets its counters between requests', function () {
    Event::dispatch(new QueryExecuted('select * from users', [], 1.0, DB::connection()));
    profileRequest();

    Event::dispatch(new QueryExecuted('select * from posts', [], 1.0, DB::connection()));
    profileRequest();

    // Two requests of one query each — not one of one and one of two.
    $row = profileRow('request_queries');
    expect((int) $row->count)->toBe(2)
        ->and((int) $row->max)->toBe(1);
});

it('ignores an unmatched route and the configured ignore list', function () {
    $request = Request::create('/vigilance/apm', 'GET');
    $request->setRouteResolver(fn () => (new Route('GET', 'vigilance/apm', []))->bind($request));

    app(RequestProfile::class)->record(CarbonImmutable::now(), $request, new Response('ok'));
    app(Apm::class)->ingest();

    expect(profileRow('request_queries'))->toBeNull();
});

it('profiles a real HTTP request end-to-end through the kernel', function () {
    RouteFacade::get('/_profile_probe', function () {
        DB::table('vigilance_entries')->count();  // our own table — never counted
        DB::table('migrations')->count();

        // A real Eloquent hydration, to prove the wildcard model event fires.
        ProbeMigration::query()->get();

        return 'ok';
    });

    $this->get('/_profile_probe')->assertOk();

    app(Apm::class)->ingest();

    $queries = profileRow('request_queries');

    expect($queries)->not->toBeNull()
        ->and($queries->key)->toBe(json_encode(['GET', '/_profile_probe']))
        ->and((int) $queries->max)->toBe(2)
        ->and((int) profileRow('request_models')->max)->toBeGreaterThan(0)
        ->and((int) profileRow('request_memory')->max)->toBeGreaterThan(0);
});

it('renders the cost columns on the Routes page', function () {
    Event::dispatch(new QueryExecuted('select * from users where id = ?', [1], 4.0, DB::connection()));
    app(Apm::class)->record('request', (string) json_encode(['GET', '/users/{user}']), 120)->count()->avg()->max();

    profileRequest();

    Livewire::test(Routes::class)
        ->assertOk()
        ->assertSee('/users/{user}')
        ->assertSee('Queries')
        ->assertSee('Models');
});

function livewireUpdateFrom(string $referer): void
{
    $request = Request::create('/livewire/update', 'POST');
    $request->setRouteResolver(fn () => (new Route('POST', 'livewire/update', []))->bind($request));
    $request->headers->set('referer', $referer);

    // The resolved path is the referring page, so the "#^/livewire/#" ignore
    // pattern no longer matches — same as the Requests / SlowRequests recorders.
    app(RequestProfile::class)->record(CarbonImmutable::now(), $request, new Response('ok'));
    app(Apm::class)->ingest();
}

it('attributes a Livewire update to the route serving the referring page', function () {
    RouteFacade::get('/orders/{order}', fn () => 'ok');

    livewireUpdateFrom('http://localhost/orders/42');

    // Collapsed to the route URI — not "/orders/42", which would mint one metric
    // key per order and blow up cardinality on a Livewire-heavy app.
    expect(profileRow('request_queries')->key)->toBe(json_encode(['POST', '/orders/{order} (livewire)']));
});

it('falls back to the concrete path when the referrer matches no route', function () {
    livewireUpdateFrom('https://example.com/somewhere-else');

    expect(profileRow('request_queries')->key)->toBe(json_encode(['POST', '/somewhere-else (livewire)']));
});
