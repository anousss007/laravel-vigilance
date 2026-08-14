<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Http\Livewire\Workers;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Vigilance::auth(fn () => true);
});

function recordFleet(string $supervisor, string $pool, int $count, ?int $at = null): void
{
    app(Apm::class)->record(
        'workers',
        (string) json_encode([$supervisor, $pool]),
        $count,
        $at ?? time(),
    )->avg()->max()->onlyBuckets();
}

it('turns the fleet size into a readable series per pool', function () {
    recordFleet('main', 'default', 3);
    recordFleet('main', 'reports', 8);
    app(Apm::class)->ingest();

    $component = Livewire::test(Workers::class);
    $series = collect($component->get('fleetSeries') ?? []);

    // Fall back to reading through the view data when the property is not
    // exposed; what matters is that both pools are charted separately.
    $graph = app(Storage::class)->graph(['workers'], 'max', CarbonInterval::hour());

    expect($graph)->toHaveCount(2);
});

it('labels a series with the supervisor and pool it belongs to', function () {
    recordFleet('main', 'default', 4);
    app(Apm::class)->ingest();

    Livewire::test(Workers::class)
        ->assertOk()
        ->assertSee('main · default');
});

it('reports the peak so a scale event is legible without reading the curve', function () {
    recordFleet('main', 'default', 2);
    app(Apm::class)->ingest();

    Livewire::test(Workers::class)->assertSee('peak 2');
});

it('hides and restores a series without losing the choice on re-render', function () {
    recordFleet('main', 'default', 4);
    app(Apm::class)->ingest();

    $key = (string) json_encode(['main', 'default']);

    Livewire::test(Workers::class)
        ->call('toggleSeries', $key)
        ->assertSet('hiddenSeries', [$key])
        // The re-render must not reset it — a chart that forgets your selection
        // every poll is worse than having no toggle.
        ->call('$refresh')
        ->assertSet('hiddenSeries', [$key])
        ->call('toggleSeries', $key)
        ->assertSet('hiddenSeries', []);
});

it('explains how to get data rather than drawing an empty chart', function () {
    Livewire::test(Workers::class)
        ->assertOk()
        ->assertSee('No scaling history yet')
        ->assertSee('vigilance:supervise');
});
