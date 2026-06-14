<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Vigilance\Http\Livewire\TraceDetail;
use Vigilance\Http\Livewire\Traces;
use Vigilance\Tracing\Contracts\TraceStorage;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(fn () => Vigilance::auth(fn () => true));

function seedTrace(array $overrides = []): string
{
    $id = (string) Str::orderedUuid();

    app(TraceStorage::class)->store(array_merge([
        'id' => $id,
        'type' => 'request',
        'name' => 'GET /reports',
        'status' => 'ok',
        'duration_ms' => 1800,
        'span_count' => 2,
        'dropped_spans' => 0,
        'user_id' => null,
        'started_at' => time(),
        'attributes' => ['method' => 'GET', 'path' => '/reports', 'status' => 200],
        'spans' => [
            ['type' => 'query', 'label' => 'select * from orders', 'offset' => 100000, 'duration' => 500000, 'attributes' => ['connection' => 'sqlite']],
            ['type' => 'cache', 'label' => 'hit config', 'offset' => 700000, 'duration' => 50, 'attributes' => ['hit' => true]],
        ],
    ], $overrides));

    return $id;
}

it('returns 200 on the traces route when authorized', function () {
    $this->get(route('vigilance.traces'))->assertOk();
});

it('renders the traces list', function () {
    seedTrace();

    Livewire::test(Traces::class)->assertOk()->assertSee('GET /reports');
});

it('filters traces by status', function () {
    seedTrace(['name' => 'GET /ok', 'status' => 'ok']);
    seedTrace(['name' => 'GET /bad', 'status' => 'error']);

    Livewire::test(Traces::class)
        ->set('status', 'error')
        ->assertSee('GET /bad')
        ->assertDontSee('GET /ok');
});

it('renders the waterfall detail with its spans', function () {
    $id = seedTrace();

    Livewire::test(TraceDetail::class, ['trace' => $id])
        ->assertOk()
        ->assertSee('Timeline')
        ->assertSee('select * from orders')
        ->assertSee('hit config');
});

it('404s an unknown trace', function () {
    Livewire::test(TraceDetail::class, ['trace' => 'does-not-exist'])->assertStatus(404);
});
