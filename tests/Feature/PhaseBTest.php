<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Http\Livewire\Pending;
use Vigilance\Metrics\PendingJobs;
use Vigilance\Metrics\Workload;
use Vigilance\Models\Run;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(fn () => Vigilance::auth(fn () => true));

it('returns null pending jobs for a non-database driver', function () {
    config()->set('queue.connections.sqsx', ['driver' => 'sqs']);

    expect(app(PendingJobs::class)->for('sqsx'))->toBeNull();
});

it('reads live pending jobs from a database queue', function () {
    config()->set('queue.connections.dbq', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs']);

    Schema::create('jobs', function ($t) {
        $t->bigIncrements('id');
        $t->string('queue')->index();
        $t->longText('payload');
        $t->unsignedTinyInteger('attempts');
        $t->unsignedInteger('reserved_at')->nullable();
        $t->unsignedInteger('available_at');
        $t->unsignedInteger('created_at');
    });

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Foo', 'data' => ['commandName' => 'App\\Jobs\\Foo']]),
        'attempts' => 0,
        'available_at' => time() - 10,
        'created_at' => time(),
    ]);

    $jobs = app(PendingJobs::class)->for('dbq');

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]['name'])->toBe('App\\Jobs\\Foo')
        ->and($jobs[0]['delayed'])->toBeFalse()
        ->and($jobs[0]['reserved'])->toBeFalse();
});

it('renders the pending page', function () {
    Livewire::test(Pending::class)->assertOk();
});

it('computes per-queue workers, wait and time-to-clear', function () {
    Run::create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\Foo',
        'status' => RunStatus::Succeeded->value,
        'connection_name' => 'database',
        'queue' => 'default',
        'duration_ms' => 100,
        'wait_ms' => 50,
        'finished_at' => now(),
    ]);

    $rows = app(Workload::class)->queues();

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toHaveKeys(['workers', 'avg_wait_ms', 'time_to_clear_ms']);
    expect($rows[0]['avg_wait_ms'])->toBe(50)
        ->and($rows[0]['workers'])->toBe(0);
});
