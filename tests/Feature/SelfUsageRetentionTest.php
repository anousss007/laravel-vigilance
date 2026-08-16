<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Http\Livewire\Usage;
use Vigilance\Metrics\SelfUsage;
use Vigilance\Models\Run;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

/**
 * The "pruning is behind" check shipped reading `vigilance.retention_days`, a
 * key that exists nowhere in the package, so it silently measured every install
 * against the 7-day fallback instead of the configured window — and then told
 * the operator their scheduler was broken. These pin both halves: the window it
 * measures, and the overhang it must tolerate between prune runs.
 */
function makeAgedRun(string $name, int $daysAgo): void
{
    Run::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => $name,
        'status' => RunStatus::Succeeded->value,
        'started_at' => now()->subDays($daysAgo),
        'finished_at' => now()->subDays($daysAgo),
        'created_at' => now()->subDays($daysAgo),
        'duration_ms' => 5,
    ]);
}

it('measures runs against the configured retention, not a fallback', function () {
    config()->set('vigilance.retention.days', 3);

    // Well past 3 days + the one-day grace, so it breaches on any reading —
    // what is being pinned is the *window the page reports*, which used to come
    // back as the phantom key's 7-day default whatever the config said.
    makeAgedRun('App\\Jobs\\Old', 30);

    $breaches = collect(app(SelfUsage::class)->retentionBreaches())->keyBy('table');

    expect($breaches['vigilance_runs']['retention'])->toBe('3 days');
});

it('reports the same window the prune command actually deletes at', function () {
    // The Usage page and vigilance:prune are two readings of one setting. When
    // they disagree the page invents a breach the prune could never fix, which
    // is exactly how this was found in production.
    $this->freezeTime();
    config()->set('vigilance.retention.days', 9);

    makeAgedRun('App\\Jobs\\Old', 40);

    $reported = collect(app(SelfUsage::class)->retentionBreaches())
        ->firstWhere('table', 'vigilance_runs')['retention'];

    // The page names the window; the command prints the cutoff it deletes
    // before. Pinning both to the same 9 days is what stops them drifting.
    $this->artisan('vigilance:prune --dry-run')
        ->expectsOutputToContain(now()->subDays(9)->toDateTimeString())
        ->assertSuccessful();

    expect($reported)->toBe('9 days');
});

it('tolerates the overhang a daily prune leaves behind', function () {
    // Traces are kept 72h and the package tells you to prune daily, so up to
    // 24h of rows past the window is the steady state of a healthy install —
    // not a backlog. Flagging it made the warning permanent for everyone who
    // followed the install instructions.
    DB::table('vigilance_traces')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'request',
        'name' => 'GET /x',
        'status' => 'ok',
        'duration_ms' => 10,
        'span_count' => 0,
        'started_at' => now()->subHours(80)->getTimestamp(),
        'created_at' => now()->subHours(80),
    ]);

    expect(app(SelfUsage::class)->retentionBreaches())->toBeEmpty();
});

it('still flags rows that outlast the window plus a full prune interval', function () {
    DB::table('vigilance_traces')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'request',
        'name' => 'GET /x',
        'status' => 'ok',
        'duration_ms' => 10,
        'span_count' => 0,
        'started_at' => now()->subHours(120)->getTimestamp(),
        'created_at' => now()->subHours(120),
    ]);

    $breaches = collect(app(SelfUsage::class)->retentionBreaches())->keyBy('table');

    expect($breaches)->toHaveKey('vigilance_traces')
        ->and($breaches['vigilance_traces']['stale'])->toBe(1);
});

it('takes the grace from the schedule this install actually runs', function () {
    // An hourly prune should not excuse a day of overhang.
    DB::table('vigilance_scheduled_tasks')->insert([
        'name' => 'vigilance:prune',
        'type' => 'command',
        'cron_expression' => '0 * * * *',
        'monitored' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(SelfUsage::class)->pruneInterval())->toBe(3600);

    DB::table('vigilance_scheduled_tasks')->update(['cron_expression' => '0 0 * * *']);

    expect(app(SelfUsage::class)->pruneInterval())->toBe(86400);
});

it('grades an uneven schedule against its widest gap', function () {
    // 09:00 and 17:00 daily alternates an 8h and a 16h stretch. Taking whichever
    // gap happens to be next makes the warning appear and vanish with the clock.
    DB::table('vigilance_scheduled_tasks')->insert([
        'name' => 'vigilance:prune',
        'type' => 'command',
        'cron_expression' => '0 9,17 * * *',
        'monitored' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(SelfUsage::class)->pruneInterval())->toBe(16 * 3600);
});

it('falls back to the recommended daily cadence when the schedule was never synced', function () {
    expect(app(SelfUsage::class)->pruneInterval())->toBe(86400);
});

it('states what it measured instead of blaming the scheduler', function () {
    // The old wording asserted "the scheduled prune is not running" — the one
    // conclusion this check cannot draw. It sent an operator hunting a failure
    // that did not exist while the prune was succeeding nightly.
    Vigilance::auth(fn () => true);

    DB::table('vigilance_traces')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'request',
        'name' => 'GET /x',
        'status' => 'ok',
        'duration_ms' => 10,
        'span_count' => 0,
        'started_at' => now()->subHours(120)->getTimestamp(),
        'created_at' => now()->subHours(120),
    ]);

    Livewire::test(Usage::class)
        ->assertOk()
        ->assertSee('Pruning is behind')
        ->assertSee('one prune interval allows')
        ->assertDontSee('the scheduled prune is not running');
});

it('does not let an unparseable cron silence the check', function () {
    DB::table('vigilance_scheduled_tasks')->insert([
        'name' => 'vigilance:prune',
        'type' => 'command',
        'cron_expression' => 'not a cron',
        'monitored' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(SelfUsage::class)->pruneInterval())->toBe(86400);
});
