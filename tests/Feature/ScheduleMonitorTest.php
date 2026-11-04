<?php

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Vigilance\Models\ScheduledTaskMonitor;
use Vigilance\Notifications\Rules\ScheduledTaskLateRule;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function monitor(array $attributes = []): ScheduledTaskMonitor
{
    return ScheduledTaskMonitor::create(array_merge([
        'name' => 'app:task',
        'type' => 'command',
        'cron_expression' => '*/5 * * * *',
        'timezone' => 'UTC',
        'grace_time_minutes' => 5,
        'monitored' => true,
        'created_at' => Carbon::now()->subDay(),
    ], $attributes));
}

it('never reports a task running on time as overdue across its lifecycle', function (string $appTz, ?string $scheduleTz) {
    config(['app.timezone' => $appTz]);
    date_default_timezone_set($appTz);
    $start = Carbon::parse('2026-11-04 10:00:00', 'UTC');
    Carbon::setTestNow($start);

    $schedule = new Schedule($scheduleTz);
    app()->instance(Schedule::class, $schedule);
    $event = $schedule->command('vigilance:snapshot')->everyFiveMinutes();
    $this->artisan('vigilance:schedule-sync')->assertSuccessful();

    foreach (range(0, 5) as $i) {
        Carbon::setTestNow($start->copy()->addMinutes(5 * $i));
        event(new ScheduledTaskStarting($event));
        Carbon::setTestNow(Carbon::now()->addSeconds(3));
        event(new ScheduledTaskFinished($event, 3.0));
        Carbon::setTestNow(Carbon::now()->addMinutes(4)->addSeconds(50));

        expect(ScheduledTaskMonitor::where('name', 'vigilance:snapshot')->sole()->isLate())->toBeFalse();
    }

    expect(iterator_to_array((new ScheduledTaskLateRule)->evaluate(), false))->toBeEmpty();
})->with([
    ['UTC', null],
    ['UTC', 'America/Montreal'],
    ['America/Montreal', null],
    ['Europe/Brussels', 'UTC'],
]);

it('reports a task that stopped running as overdue', function () {
    $task = monitor([
        'last_started_at' => Carbon::now()->subMinutes(31),
        'last_finished_at' => Carbon::now()->subMinutes(30),
    ]);

    expect($task->isLate())->toBeTrue();
});

it('reports a task that never ran as overdue', function () {
    expect(monitor()->isLate())->toBeTrue();
});

it('does not report a skipped task as overdue', function () {
    $task = monitor([
        'last_finished_at' => Carbon::now()->subDay(),
        'last_skipped_at' => Carbon::now()->subMinute(),
    ]);

    expect($task->isLate())->toBeFalse();
});

it('does not report a task that started on time but is still running as overdue', function () {
    $task = monitor([
        'last_started_at' => Carbon::now()->subMinute(),
        'last_finished_at' => Carbon::now()->subDay(),
    ]);

    expect($task->isLate())->toBeFalse();
});

it('reports a task that started long ago and never finished as overdue', function () {
    $task = monitor([
        'last_started_at' => Carbon::now()->subMinutes(30),
        'last_finished_at' => Carbon::now()->subDay(),
    ]);

    expect($task->isLate())->toBeTrue();
});

it('records skipped runs from the scheduler', function () {
    $event = (new Schedule)->command('app:task')->everyFiveMinutes();

    event(new ScheduledTaskSkipped($event));

    expect(ScheduledTaskMonitor::where('name', 'app:task')->sole()->last_skipped_at)->not->toBeNull();
});

it('logs instead of silently dropping a run it cannot record', function () {
    Schema::drop('vigilance_scheduled_tasks');
    Log::spy();

    $event = (new Schedule)->command('app:task')->everyFiveMinutes();
    event(new ScheduledTaskFinished($event, 1.0));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'could not record a scheduled task run'))
        ->once();
});

it('applies the configured grace window, with per-task overrides', function () {
    config([
        'vigilance.schedule.grace_minutes' => 10,
        'vigilance.schedule.grace' => ['vigilance:*' => 15, 'backup:run' => 60],
    ]);

    $schedule = new Schedule;
    app()->instance(Schedule::class, $schedule);
    $schedule->command('vigilance:schedule-sync')->hourly();
    $schedule->command('backup:run')->daily();
    $schedule->command('app:report')->daily();

    $this->artisan('vigilance:schedule-sync')->assertSuccessful();

    expect(ScheduledTaskMonitor::pluck('grace_time_minutes', 'name')->all())->toBe([
        'vigilance:schedule-sync' => 15,
        'backup:run' => 60,
        'app:report' => 10,
    ]);
});

it('refreshes the grace window when a task runs', function () {
    $event = (new Schedule)->command('app:task')->everyFiveMinutes();
    event(new ScheduledTaskStarting($event));

    config(['vigilance.schedule.grace' => ['app:task' => 20]]);
    event(new ScheduledTaskFinished($event, 1.0));

    expect(ScheduledTaskMonitor::where('name', 'app:task')->sole()->grace_time_minutes)->toBe(20);
});

it('does not flag a task delayed behind slower ones within its grace window', function () {
    // Hourly task due at 16:00, queued behind slow tasks until 16:08:45.
    Carbon::setTestNow(Carbon::parse('2026-11-04 16:06:00', 'UTC'));

    $task = monitor([
        'name' => 'vigilance:schedule-sync',
        'cron_expression' => '0 * * * *',
        'last_started_at' => Carbon::parse('2026-11-04 15:08:45', 'UTC'),
        'last_finished_at' => Carbon::parse('2026-11-04 15:08:46', 'UTC'),
    ]);

    expect($task->isLate())->toBeTrue();

    $task->update(['grace_time_minutes' => 15]);

    expect($task->fresh()->isLate())->toBeFalse();
});

it('says when the task was due and when it last started', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 16:20:00', 'UTC'));

    monitor([
        'name' => 'vigilance:schedule-sync',
        'cron_expression' => '0 * * * *',
        'last_started_at' => Carbon::parse('2026-11-04 15:08:45', 'UTC'),
        'last_finished_at' => Carbon::parse('2026-11-04 15:08:46', 'UTC'),
    ]);

    $alert = iterator_to_array((new ScheduledTaskLateRule)->evaluate(), false)[0];

    expect($alert->message)
        ->toContain('should have started by 2026-11-04 16:00:00 UTC (5 min grace)')
        ->toContain('Last started 2026-11-04 15:08:45 UTC');
});
