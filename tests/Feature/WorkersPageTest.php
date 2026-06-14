<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Vigilance\Http\Livewire\Workers;
use Vigilance\Models\SupervisorRecord;
use Vigilance\Models\WorkerRecord;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(fn () => Vigilance::auth(fn () => true));

it('renders running supervisors and their workers', function () {
    SupervisorRecord::create([
        'name' => 'supervisor-1', 'host' => 'host', 'pid' => 1, 'status' => 'running',
        'connection' => 'database', 'queues' => 'default', 'balance' => 'auto',
        'processes' => 1, 'pools' => ['default' => 1], 'options' => [], 'last_heartbeat_at' => now(),
    ]);
    WorkerRecord::create([
        'supervisor' => 'supervisor-1', 'pid' => 4242, 'connection' => 'database',
        'queue' => 'default', 'status' => 'running', 'last_heartbeat_at' => now(),
    ]);

    Livewire::test(Workers::class)
        ->assertOk()
        ->assertSee('supervisor-1')
        ->assertSee('4242');
});

it('controls supervisors through the dashboard', function () {
    app(ControlPlane::class)->reset();

    Livewire::test(Workers::class)->call('pause');
    expect(app(ControlPlane::class)->isPaused())->toBeTrue();

    Livewire::test(Workers::class)->call('resume');
    expect(app(ControlPlane::class)->status())->toBe(ControlPlane::RUNNING);

    Livewire::test(Workers::class)->call('restart');
    expect(app(ControlPlane::class)->restartToken())->not->toBeNull();
});
