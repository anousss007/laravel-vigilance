<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Vigilance\Models\SupervisorRecord;
use Vigilance\Models\WorkerRecord;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Supervision\SupervisorOptions;
use Vigilance\Supervision\SupervisorState;

uses(RefreshDatabase::class);

it('drives the control plane through cache flags', function () {
    $control = new ControlPlane;
    $control->reset();

    expect($control->status())->toBe(ControlPlane::RUNNING)
        ->and($control->isPaused())->toBeFalse();

    $control->pause();
    expect($control->isPaused())->toBeTrue();

    $control->continue();
    expect($control->status())->toBe(ControlPlane::RUNNING);

    $control->terminate();
    expect($control->isTerminating())->toBeTrue();

    expect($control->restartToken())->toBeNull();
    $control->restart();
    expect($control->restartToken())->not->toBeNull();
});

it('writes and reads supervisor + worker heartbeats', function () {
    $state = new SupervisorState;
    $options = SupervisorOptions::fromArray([
        'name' => 'supervisor-1',
        'connection' => 'database',
        'queue' => ['default'],
        'balance' => 'auto',
    ]);

    $state->heartbeat($options, 'running', ['default' => 2], [
        ['pid' => 111, 'queue' => 'default'],
        ['pid' => 112, 'queue' => 'default'],
    ]);

    $supervisor = SupervisorRecord::find('supervisor-1');
    expect($supervisor)->not->toBeNull()
        ->and($supervisor->processes)->toBe(2)
        ->and($supervisor->status)->toBe('running')
        ->and($supervisor->pools)->toBe(['default' => 2]);

    expect(WorkerRecord::query()->where('supervisor', 'supervisor-1')->count())->toBe(2);

    expect($state->active(30))->toHaveCount(1);

    // A re-heartbeat replaces the worker set rather than duplicating it.
    $state->heartbeat($options, 'running', ['default' => 1], [['pid' => 113, 'queue' => 'default']]);
    expect(WorkerRecord::query()->where('supervisor', 'supervisor-1')->count())->toBe(1);
});

it('prunes supervisors that stopped heartbeating', function () {
    $state = new SupervisorState;
    $options = SupervisorOptions::fromArray(['name' => 'supervisor-1', 'connection' => 'database']);

    $state->heartbeat($options, 'running', ['default' => 1], [['pid' => 1, 'queue' => 'default']]);

    SupervisorRecord::query()->whereKey('supervisor-1')->update(['last_heartbeat_at' => Carbon::now()->subMinutes(5)]);

    expect($state->active(30))->toHaveCount(0);

    $state->pruneExpired(30);

    expect(SupervisorRecord::query()->count())->toBe(0)
        ->and(WorkerRecord::query()->count())->toBe(0);
});
