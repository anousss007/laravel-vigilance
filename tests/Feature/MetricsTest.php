<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vigilance\Contracts\MetricsRepository;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Metrics\QueueDepth;
use Vigilance\Metrics\Stats;
use Vigilance\Metrics\Workload;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;
use Vigilance\Models\ScheduledTaskMonitor;

uses(RefreshDatabase::class);

/**
 * Seed a Run with sensible defaults; override anything via $attributes.
 */
function seedRun(array $attributes = []): Run
{
    static $seq = 0;
    $seq++;

    return Run::create(array_merge([
        'uuid' => "uuid-$seq",
        'type' => RunType::Job,
        'name' => 'App\\Jobs\\DoThing',
        'status' => RunStatus::Succeeded,
        'connection_name' => 'database',
        'queue' => 'default',
        'attempt' => 1,
        'duration_ms' => 100,
        'wait_ms' => 10,
        'started_at' => Carbon::now()->subSeconds(5),
        'finished_at' => Carbon::now(),
    ], $attributes));
}

it('computes window counts and success rate', function () {
    seedRun(['status' => RunStatus::Succeeded]);
    seedRun(['status' => RunStatus::Succeeded]);
    seedRun(['status' => RunStatus::Succeeded]);
    seedRun(['status' => RunStatus::Failed]);
    seedRun(['status' => RunStatus::Running, 'finished_at' => null]);
    seedRun(['status' => RunStatus::Queued, 'finished_at' => null]);

    // Outside the window -> must be excluded.
    seedRun(['status' => RunStatus::Succeeded, 'created_at' => Carbon::now()->subDays(3)]);

    $counts = (new Stats)->counts('24h');

    expect($counts['total'])->toBe(6)
        ->and($counts['succeeded'])->toBe(3)
        ->and($counts['failed'])->toBe(1)
        ->and($counts['running'])->toBe(1)
        ->and($counts['queued'])->toBe(1)
        // 3 succeeded / (3 + 1 failed) = 75.0
        ->and($counts['success_rate'])->toBe(75.0);
});

it('returns a zero-filled per-minute throughput series of the right length', function () {
    seedRun(['status' => RunStatus::Succeeded, 'finished_at' => Carbon::now()]);
    seedRun(['status' => RunStatus::Failed, 'finished_at' => Carbon::now()]);
    seedRun(['status' => RunStatus::Succeeded, 'finished_at' => Carbon::now()->subMinutes(2)]);
    // Older than the window: ignored.
    seedRun(['status' => RunStatus::Succeeded, 'finished_at' => Carbon::now()->subMinutes(90)]);

    $series = (new Stats)->throughputPerMinute(60);

    expect($series)->toHaveCount(60);

    foreach ($series as $bucket) {
        expect($bucket)->toHaveKeys(['t', 'count', 'failed']);
    }

    // The most recent bucket holds the two just-finished runs (1 of them failed).
    $last = $series[count($series) - 1];
    expect($last['count'])->toBe(2)
        ->and($last['failed'])->toBe(1);

    // Totals across the series exclude the 90-minutes-ago run.
    $total = array_sum(array_column($series, 'count'));
    expect($total)->toBe(3);
});

it('lists unresolved failure groups by last seen', function () {
    FailureGroup::create([
        'signature' => 'sig-old',
        'name' => 'App\\Jobs\\Old',
        'exception_class' => 'RuntimeException',
        'message' => 'old boom',
        'occurrences' => 2,
        'first_seen_at' => Carbon::now()->subHours(5),
        'last_seen_at' => Carbon::now()->subHours(4),
    ]);

    FailureGroup::create([
        'signature' => 'sig-new',
        'name' => 'App\\Jobs\\New',
        'exception_class' => 'LogicException',
        'message' => 'new boom',
        'occurrences' => 9,
        'first_seen_at' => Carbon::now()->subHour(),
        'last_seen_at' => Carbon::now()->subMinutes(10),
    ]);

    FailureGroup::create([
        'signature' => 'sig-resolved',
        'name' => 'App\\Jobs\\Fixed',
        'exception_class' => 'LogicException',
        'message' => 'fixed boom',
        'occurrences' => 1,
        'first_seen_at' => Carbon::now()->subDay(),
        'last_seen_at' => Carbon::now()->subMinute(),
        'resolved_at' => Carbon::now(),
    ]);

    $top = (new Stats)->topFailing(5);

    expect($top)->toHaveCount(2)
        ->and($top->first()->name)->toBe('App\\Jobs\\New')
        ->and($top->pluck('name'))->not->toContain('App\\Jobs\\Fixed');
});

it('counts unreserved database-queue rows and returns null for unknown drivers', function () {
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'jobs',
        'queue' => 'default',
    ]);
    config()->set('queue.connections.sqsish', [
        'driver' => 'sqs',
    ]);

    Schema::create('jobs', function ($table) {
        $table->bigIncrements('id');
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    $insert = fn (string $queue, ?int $reservedAt) => DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAt,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    $insert('default', null);          // pending
    $insert('default', null);          // pending
    $insert('default', time());        // reserved -> not counted
    $insert('emails', null);           // different queue -> not counted

    $depth = new QueueDepth;

    expect($depth->for('database', 'default'))->toBe(2)
        ->and($depth->for('database', 'emails'))->toBe(1)
        ->and($depth->for('sqsish', 'default'))->toBeNull()
        ->and($depth->for('does-not-exist', 'default'))->toBeNull();
});

it('flags scheduled tasks that are late', function () {
    // A daily task whose last finish was two days ago is well past its next
    // expected run + grace, so it must read as late.
    $task = ScheduledTaskMonitor::create([
        'name' => 'app:digest',
        'type' => 'command',
        'cron_expression' => '0 0 * * *',
        'timezone' => 'UTC',
        'grace_time_minutes' => 5,
        'monitored' => true,
        'last_started_at' => Carbon::now()->subDays(2)->subMinutes(1),
        'last_finished_at' => Carbon::now()->subDays(2),
    ]);

    // A frequent task that just finished is on time.
    ScheduledTaskMonitor::create([
        'name' => 'app:heartbeat',
        'type' => 'command',
        'cron_expression' => '* * * * *',
        'timezone' => 'UTC',
        'grace_time_minutes' => 5,
        'monitored' => true,
        'last_started_at' => Carbon::now()->subSeconds(30),
        'last_finished_at' => Carbon::now()->subSeconds(20),
        'last_failed_at' => Carbon::now()->subSeconds(20),
    ]);

    $tasks = (new Workload(new QueueDepth, app(MetricsRepository::class)))
        ->scheduledTasks();

    $late = $tasks->firstWhere('name', 'app:digest');
    $onTime = $tasks->firstWhere('name', 'app:heartbeat');

    expect($task->isLate())->toBeTrue()
        ->and($late->is_late)->toBeTrue()
        ->and($onTime->is_late)->toBeFalse()
        ->and($onTime->last_run_failed)->toBeTrue();
});
