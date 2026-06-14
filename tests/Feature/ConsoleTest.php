<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Entry;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\MetricSnapshot;
use Vigilance\Models\Run;
use Vigilance\Models\ScheduledTaskMonitor;
use Vigilance\Tracing\Contracts\TraceStorage;

uses(RefreshDatabase::class);

function makeRun(string $status, Carbon $createdAt, array $extra = []): Run
{
    $run = Run::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\Demo',
        'status' => $status,
    ], $extra));

    Run::query()->whereKey($run->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

    return $run->refresh();
}

it('prunes old non-failed runs but keeps recent and within-window failures', function () {
    makeRun(RunStatus::Succeeded->value, Carbon::now()->subDays(30)); // delete (>14d)
    makeRun(RunStatus::Succeeded->value, Carbon::now());              // keep
    makeRun(RunStatus::Failed->value, Carbon::now()->subDays(20));    // keep (<30d failed)
    makeRun(RunStatus::Failed->value, Carbon::now()->subDays(40));    // delete (>30d failed)

    $this->artisan('vigilance:prune')->assertSuccessful();

    expect(Run::query()->count())->toBe(2);
});

it('trims old apm telemetry when pruning', function () {
    $storage = app(Storage::class);

    $storage->store(collect([
        (new Entry(time() - 9 * 86400, 'apm_prune', 'old', 1))->count(), // > 7 days
        (new Entry(time(), 'apm_prune', 'fresh', 1))->count(),
    ]));

    expect(DB::table('vigilance_entries')->where('type', 'apm_prune')->count())->toBe(2);

    $this->artisan('vigilance:prune')->assertSuccessful();

    // The stale raw entry is gone; the fresh one survives.
    expect(DB::table('vigilance_entries')->where('type', 'apm_prune')->where('key', 'old')->count())->toBe(0)
        ->and(DB::table('vigilance_entries')->where('type', 'apm_prune')->where('key', 'fresh')->count())->toBe(1);
});

it('trims old traces when pruning', function () {
    config()->set('vigilance.tracing.enabled', true);
    config()->set('vigilance.tracing.retention', '72 hours');

    $storage = app(TraceStorage::class);

    $trace = fn (string $id, int $ts) => [
        'id' => $id, 'type' => 'request', 'name' => 'GET /x', 'status' => 'ok',
        'duration_ms' => 10, 'span_count' => 0, 'dropped_spans' => 0, 'user_id' => null,
        'started_at' => $ts, 'attributes' => [], 'spans' => [],
    ];

    $storage->store($trace('old', time() - 4 * 86400));   // > 72h
    $storage->store($trace('fresh', time()));

    expect(DB::table('vigilance_traces')->count())->toBe(2);

    $this->artisan('vigilance:prune')->assertSuccessful();

    expect(DB::table('vigilance_traces')->where('id', 'old')->count())->toBe(0)
        ->and(DB::table('vigilance_traces')->where('id', 'fresh')->count())->toBe(1);
});

it('captures metric snapshots for finished job runs', function () {
    makeRun(RunStatus::Succeeded->value, Carbon::now()->subMinute(), [
        'queue' => 'default',
        'duration_ms' => 120,
        'finished_at' => Carbon::now()->subMinute(),
    ]);

    $this->artisan('vigilance:snapshot')->assertSuccessful();

    expect(MetricSnapshot::query()->where('scope_type', 'job')->count())->toBeGreaterThan(0);
});

it('syncs defined scheduled tasks into monitors', function () {
    app(Schedule::class)->command('inspire')->daily();

    $this->artisan('vigilance:schedule-sync')->assertSuccessful();

    expect(ScheduledTaskMonitor::query()->where('name', 'inspire')->exists())->toBeTrue();
});
