<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Vigilance\Apm\Apm;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Http\Livewire\Usage;
use Vigilance\Metrics\SelfUsage;
use Vigilance\Models\Run;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Vigilance::auth(fn () => true);
});

it('counts what each telemetry type is storing', function () {
    app(Apm::class)->record('request', 'GET /x', 100)->count();
    app(Apm::class)->ingest();

    $tables = collect(app(SelfUsage::class)->tables())->keyBy('table');

    expect($tables['vigilance_entries']['rows'])->toBeGreaterThan(0)
        ->and($tables['vigilance_entries']['last_day'])->toBeGreaterThan(0);
});

it('names the knob that turns each one down', function () {
    // The page exists to get from "this is big" to "here is what to change";
    // a row with no lever is a dead end.
    $tables = collect(app(SelfUsage::class)->tables())->keyBy('table');

    expect($tables['vigilance_entries']['lever'])->toContain('sample_rate')
        ->and($tables['vigilance_logs']['lever'])->toContain('logs.');
});

it('ranks the heaviest table first', function () {
    foreach (range(1, 5) as $i) {
        app(Apm::class)->record('request', 'GET /x'.$i, 100)->count();
    }
    app(Apm::class)->ingest();

    expect(app(SelfUsage::class)->tables()[0]['rows'])->toBeGreaterThan(0);
});

it('compares APM timestamps as unix seconds, not datetimes', function () {
    // The APM layer stores unix seconds while the rest store datetimes;
    // comparing the wrong kind is how these counts silently come back zero.
    app(Apm::class)->record('request', 'GET /x', 100)->count();
    app(Apm::class)->ingest();

    $tables = collect(app(SelfUsage::class)->tables())->keyBy('table');

    expect($tables['vigilance_entries']['last_day'])->toBeGreaterThan(0)
        ->and($tables['vigilance_entries']['oldest'])->not->toBeNull();
});

it('reads a datetime-based table correctly too', function () {
    Run::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\Thing',
        'status' => RunStatus::Succeeded->value,
        'started_at' => now(),
        'finished_at' => now(),
        'duration_ms' => 5,
    ]);

    $tables = collect(app(SelfUsage::class)->tables())->keyBy('table');

    expect($tables['vigilance_runs']['rows'])->toBe(1)
        ->and($tables['vigilance_runs']['last_day'])->toBe(1);
});

it('flags data left past its retention window', function () {
    config()->set('vigilance.retention.days', 1);

    Run::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\Old',
        'status' => RunStatus::Succeeded->value,
        'started_at' => now()->subDays(30),
        'finished_at' => now()->subDays(30),
        'created_at' => now()->subDays(30),
        'duration_ms' => 5,
    ]);

    $breaches = collect(app(SelfUsage::class)->retentionBreaches())->keyBy('table');

    expect($breaches)->toHaveKey('vigilance_runs')
        ->and($breaches['vigilance_runs']['stale'])->toBe(1);
});

it('stays quiet when pruning is keeping up', function () {
    expect(app(SelfUsage::class)->retentionBreaches())->toBeEmpty();
});

it('shows a blank cell instead of failing when a table was never migrated', function () {
    // Optional features leave their table absent; the monitoring page must not
    // 500 because the log explorer was never turned on.
    DB::statement('drop table vigilance_logs');

    $tables = collect(app(SelfUsage::class)->tables())->keyBy('table');

    expect($tables['vigilance_logs']['rows'])->toBeNull();
});

it('renders the page', function () {
    app(Apm::class)->record('request', 'GET /x', 100)->count();
    app(Apm::class)->ingest();

    Livewire::test(Usage::class)
        ->assertOk()
        ->assertSee('Rows stored')
        ->assertSee('APM entries')
        ->assertSee('sample_rate');
});
