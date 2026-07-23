<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Control\QueueManager;
use Vigilance\Http\Livewire\Pending;
use Vigilance\Http\Livewire\Workload;
use Vigilance\Metrics\QueueDepth;
use Vigilance\Models\AuditEntry;
use Vigilance\Supervision\AutoScaler;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Supervision\Pool;
use Vigilance\Supervision\Supervisor;
use Vigilance\Supervision\SupervisorOptions;
use Vigilance\Supervision\SupervisorState;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::store()->forget('vigilance:control:paused-queues');
});

/**
 * Create the standard Laravel queue "jobs" table on the test connection and make
 * sure it is empty. DDL auto-commits on MySQL (breaking RefreshDatabase's
 * per-test transaction), so rows can otherwise leak between tests — purge to keep
 * assertions deterministic across sqlite / mysql / postgres.
 */
function makeJobsTable(): void
{
    if (! Schema::hasTable('jobs')) {
        Schema::create('jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
    }

    DB::table('jobs')->delete();
}

// ── ControlPlane: per-queue pause ────────────────────────────────────────────

it('pauses and resumes a single queue without touching others', function () {
    $control = app(ControlPlane::class);

    $control->pauseQueue('database', 'emails');

    expect($control->isQueuePaused('database', 'emails'))->toBeTrue()
        ->and($control->isQueuePaused('database', 'default'))->toBeFalse()
        ->and($control->isQueuePaused('redis', 'emails'))->toBeFalse();

    $control->continueQueue('database', 'emails');

    expect($control->isQueuePaused('database', 'emails'))->toBeFalse()
        ->and($control->pausedQueues())->toBe([]);
});

it('lists paused queues with their expiry', function () {
    $control = app(ControlPlane::class);

    $control->pauseQueue('database', 'a');
    $control->pauseQueue('database', 'b', 300);

    $paused = collect($control->pausedQueues())->keyBy('queue');

    expect($paused)->toHaveCount(2)
        ->and($paused['a']['expires_at'])->toBeNull()
        ->and($paused['b']['expires_at'])->toBeGreaterThan(time());
});

it('auto-expires a lapsed timed pause and keeps indefinite ones', function () {
    // Seed the cache directly with one already-lapsed and one indefinite entry.
    Cache::store()->forever('vigilance:control:paused-queues', [
        'database' => ['lapsed' => time() - 5, 'forever' => null],
    ]);

    $control = app(ControlPlane::class);

    expect($control->isQueuePaused('database', 'lapsed'))->toBeFalse()
        ->and($control->isQueuePaused('database', 'forever'))->toBeTrue()
        ->and($control->pausedQueues())->toHaveCount(1);
});

// ── Supervisor: honours a paused queue ───────────────────────────────────────

it('runs no workers for a fully paused supervisor pool', function () {
    $options = SupervisorOptions::fromArray([
        'name' => 'pause-sup', 'connection' => 'database', 'queue' => ['emails'],
        'balance' => false, 'min_processes' => 1, 'max_processes' => 2,
    ]);

    app(ControlPlane::class)->pauseQueue('database', 'emails');

    $supervisor = new Supervisor(
        $options,
        new AutoScaler,
        app(SupervisorState::class),
        app(ControlPlane::class),
        app(QueueDepth::class),
    );

    $supervisor->tick();

    // The only queue is paused → no worker process is ever launched.
    expect($supervisor->totalProcesses())->toBe(0);
});

it('never launches a pool whose active queue set is empty', function () {
    $options = SupervisorOptions::fromArray(['queue' => ['default'], 'balance' => false]);
    $pool = new Pool('default', $options);

    $pool->setActiveQueues('');
    $pool->scaleTo(3);

    expect($pool->count())->toBe(0);
});

it('narrows a multi-queue pool to only its unpaused queues (balance=false)', function () {
    // balance=false → a single pool serving "high,low". min_processes=0 keeps the
    // tick from spawning any real worker process, so we can assert the narrowing
    // decision deterministically.
    $options = SupervisorOptions::fromArray([
        'name' => 'narrow-sup', 'connection' => 'database', 'queue' => ['high', 'low'],
        'balance' => false, 'min_processes' => 0, 'max_processes' => 2,
    ]);

    $control = app(ControlPlane::class);
    $control->pauseQueue('database', 'low');

    $supervisor = new Supervisor(
        $options, new AutoScaler, app(SupervisorState::class), $control, app(QueueDepth::class),
    );

    $supervisor->tick();
    expect($supervisor->poolActiveQueues())->toBe(['high,low' => 'high'])
        ->and($supervisor->totalProcesses())->toBe(0);

    // Resuming restores the full queue set on the next tick.
    $control->continueQueue('database', 'low');
    $supervisor->tick();
    expect($supervisor->poolActiveQueues())->toBe(['high,low' => 'high,low']);
});

it('narrows to nothing when every queue in a balance=false pool is paused', function () {
    $options = SupervisorOptions::fromArray([
        'name' => 'all-paused', 'connection' => 'database', 'queue' => ['high', 'low'],
        'balance' => false, 'min_processes' => 0, 'max_processes' => 2,
    ]);

    $control = app(ControlPlane::class);
    $control->pauseQueue('database', 'high');
    $control->pauseQueue('database', 'low');

    $supervisor = new Supervisor(
        $options, new AutoScaler, app(SupervisorState::class), $control, app(QueueDepth::class),
    );

    $supervisor->tick();

    expect($supervisor->poolActiveQueues())->toBe(['high,low' => ''])
        ->and($supervisor->totalProcesses())->toBe(0);
});

// ── CLI wiring ───────────────────────────────────────────────────────────────

it('pauses and resumes a queue through the artisan commands', function () {
    config()->set('vigilance.defaults.connection', 'database');

    $this->artisan('vigilance:pause', ['--queue' => 'emails', '--for' => '120'])
        ->assertSuccessful();

    expect(app(ControlPlane::class)->isQueuePaused('database', 'emails'))->toBeTrue();

    $this->artisan('vigilance:status')->assertSuccessful()->expectsOutputToContain('emails');

    $this->artisan('vigilance:continue', ['--queue' => 'emails'])->assertSuccessful();

    expect(app(ControlPlane::class)->isQueuePaused('database', 'emails'))->toBeFalse();
});

it('still pauses the whole fleet when no --queue is given', function () {
    $this->artisan('vigilance:pause')->assertSuccessful();
    expect(app(ControlPlane::class)->isPaused())->toBeTrue();

    $this->artisan('vigilance:continue')->assertSuccessful();
    expect(app(ControlPlane::class)->status())->toBe(ControlPlane::RUNNING);
});

// ── QueueManager: clear + cancel are gated and audited ───────────────────────

it('refuses to clear or cancel when manual control is disabled', function () {
    config()->set('vigilance.control.enabled', false);

    expect(fn () => app(QueueManager::class)->clear('database', 'default'))
        ->toThrow(NotAllowed::class);

    expect(fn () => app(QueueManager::class)->deletePending('database', [1]))
        ->toThrow(NotAllowed::class);
});

it('clears a queue and audits it', function () {
    config()->set('vigilance.control.enabled', true);
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']);
    makeJobsTable();

    foreach (['default', 'default', 'other'] as $q) {
        DB::table('jobs')->insert([
            'queue' => $q, 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);
    }

    $deleted = app(QueueManager::class)->clear('database', 'default', 'admin@test');

    expect($deleted)->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(1); // "other" is untouched

    $audit = AuditEntry::query()->where('action', 'clear_queue')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->user)->toBe('admin@test')
        ->and($audit->subject)->toBe('database:default');
});

it('cancels selected pending jobs by id and audits it', function () {
    config()->set('vigilance.control.enabled', true);
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']);
    makeJobsTable();

    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $ids[] = DB::table('jobs')->insertGetId([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);
    }

    $deleted = app(QueueManager::class)->deletePending('database', [$ids[0], $ids[2]], 'admin@test');

    expect($deleted)->toBe(2)
        ->and(DB::table('jobs')->pluck('id')->all())->toBe([$ids[1]]);

    expect(AuditEntry::query()->where('action', 'cancel_pending')->exists())->toBeTrue();
});

// ── Livewire wiring ──────────────────────────────────────────────────────────

it('pauses and resumes a queue from the workload page', function () {
    Vigilance::auth(fn () => true);

    Livewire::test(Workload::class)->call('pauseQueue', 'database', 'emails', 15);
    $paused = collect(app(ControlPlane::class)->pausedQueues())->firstWhere('queue', 'emails');
    expect($paused)->not->toBeNull()
        ->and($paused['expires_at'])->toBeGreaterThan(time());

    Livewire::test(Workload::class)->call('resumeQueue', 'database', 'emails');
    expect(app(ControlPlane::class)->isQueuePaused('database', 'emails'))->toBeFalse();
});

it('cancels pending jobs from the pending page when control is enabled', function () {
    Vigilance::auth(fn () => true);
    config()->set('vigilance.control.enabled', true);
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']);
    makeJobsTable();

    $id = DB::table('jobs')->insertGetId([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
    ]);

    Livewire::test(Pending::class)
        ->set('selected', ['database#'.$id])
        ->call('cancelSelected', 'database');

    expect(DB::table('jobs')->where('id', $id)->exists())->toBeFalse();
});
