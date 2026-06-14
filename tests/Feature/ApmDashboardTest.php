<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Vigilance\Apm\Apm;
use Vigilance\Http\Livewire\Apm as ApmPage;
use Vigilance\Http\Livewire\ApmCard;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Vigilance::auth(fn () => true);
});

function seedApm(): void
{
    $apm = app(Apm::class);

    $apm->set('system', 'web-1', (string) json_encode([
        'name' => 'web-1', 'cpu' => 23, 'memory_used' => 4096, 'memory_total' => 8192,
        'storage' => [], 'updated_at' => time(),
    ]));
    $apm->record('cpu', 'web-1', 23)->avg()->onlyBuckets();
    $apm->record('memory', 'web-1', 4096)->avg()->onlyBuckets();
    $apm->record('slow_request', (string) json_encode(['GET', '/users/{user}']), 1800)->max()->count();
    $apm->record('slow_query', (string) json_encode(['sql' => 'select * from orders', 'location' => 'Order.php:42']), 2500)->max()->count();
    $apm->record('exception', (string) json_encode(['class' => 'RuntimeException', 'location' => 'Foo.php:10']), time())->max()->count();
    $apm->record('cache_hit', 'config', null)->count();
    $apm->record('cache_miss', 'user:7', null)->count();
    $apm->record('slow_job', 'App\\Jobs\\HeavyReport', 2500)->max()->count();
    $apm->set('user', '5', (string) json_encode(['id' => 5, 'name' => 'Sam Carter', 'extra' => '']));
    $apm->record('user_job', '5', null)->count();
    $apm->record('queue_processed', 'database:default', null)->count()->onlyBuckets();

    $apm->ingest();
}

it('returns 200 on the apm route when authorized', function () {
    $this->get(route('vigilance.apm'))->assertOk();
});

it('returns 403 on the apm route when not authorized', function () {
    Vigilance::auth(fn () => false);
    $this->get(route('vigilance.apm'))->assertForbidden();
});

it('renders the apm shell with the period selector and lazy cards', function () {
    Livewire::test(ApmPage::class)
        ->assertOk()
        ->assertSee('Application performance')
        ->assertSeeLivewire('vigilance.apm-card')
        ->assertSet('period', '1h')
        ->call('setPeriod', '7d')
        ->assertSet('period', '7d');
});

it('renders the cache card', function () {
    seedApm();

    Livewire::test(ApmCard::class, ['card' => 'cache'])
        ->call('$refresh') // resolve the lazy placeholder
        ->assertOk()
        ->assertSee('Cache hit rate');
});

it('renders the slow-requests card with telemetry', function () {
    seedApm();

    Livewire::test(ApmCard::class, ['card' => 'slow_requests'])
        ->call('$refresh')
        ->assertOk()
        ->assertSee('/users/{user}');
});

it('renders the usage card and switches to the jobs dimension', function () {
    seedApm();

    Livewire::test(ApmCard::class, ['card' => 'usage'])
        ->assertOk()
        ->call('setUsageMode', 'jobs')
        ->assertSet('usageMode', 'jobs')
        ->assertSee('Sam Carter');
});

it('resolves user display and avatar at read time', function () {
    Vigilance::resolveApmUsersUsing(fn ($ids) => in_array('77', $ids, true)
        ? ['77' => ['name' => 'Resolved Riley', 'extra' => 'riley@x.test', 'avatar' => 'https://avatar.test/riley.png']]
        : []);

    $apm = app(Apm::class);
    $apm->record('user_request', '77', null)->count();
    $apm->ingest();

    Livewire::test(ApmCard::class, ['card' => 'usage'])
        ->call('$refresh')
        ->assertSee('Resolved Riley')
        ->assertSee('avatar.test/riley.png');
});

it('renders the job throughput card', function () {
    seedApm();

    Livewire::test(ApmCard::class, ['card' => 'throughput'])
        ->call('$refresh')
        ->assertOk()
        ->assertSee('database:default');
});

it('falls back to a missing-card notice for an unknown card', function () {
    Livewire::test(ApmCard::class, ['card' => 'nonsense'])
        ->call('$refresh')
        ->assertOk()
        ->assertSee('Unknown card');
});
