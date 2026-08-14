<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Supervision\AutoScaler;
use Vigilance\Supervision\QueueWait;
use Vigilance\Supervision\SupervisorOptions;

uses(RefreshDatabase::class);

function latencyOptions(array $overrides = []): SupervisorOptions
{
    return SupervisorOptions::fromArray(array_merge([
        'name' => 'main',
        'connection' => 'redis',
        'queue' => ['default', 'reports'],
        'balance' => 'auto',
        'auto_scaling_strategy' => 'latency',
        'min_processes' => 1,
        'max_processes' => 10,
        'balance_max_shift' => 10,
        'target_wait_ms' => 5000,
    ], $overrides));
}

it('deploys the whole fleet once the measured wait reaches the target', function () {
    $desired = app(AutoScaler::class)->desiredPerPool(
        latencyOptions(),
        sizeFor: fn () => 100,
        runtimeFor: null,
        waitFor: fn (string $pool) => $pool === 'default' ? 5000.0 : 5000.0,
    );

    expect(array_sum($desired))->toBe(10);
});

it('deploys proportionally less while latency is comfortably under target', function () {
    // Half the target wait means half the fleet — this is the part the other
    // strategies cannot do: they deploy everything the moment anything queues.
    $desired = app(AutoScaler::class)->desiredPerPool(
        latencyOptions(),
        sizeFor: fn () => 100,
        runtimeFor: null,
        waitFor: fn () => 2500.0,
    );

    expect(array_sum($desired))->toBe(5);
});

it('scales back to the minimum once latency recovers', function () {
    $desired = app(AutoScaler::class)->desiredPerPool(
        latencyOptions(),
        sizeFor: fn () => 100,
        runtimeFor: null,
        waitFor: fn () => 0.0,
    );

    // One per pool, the floor — not the whole fleet sitting idle.
    expect($desired)->toBe(['default' => 1, 'reports' => 1]);
});

it('gives the fleet to the queue that is actually waiting', function () {
    $desired = app(AutoScaler::class)->desiredPerPool(
        latencyOptions(),
        sizeFor: fn () => 50,
        runtimeFor: null,
        waitFor: fn (string $pool) => $pool === 'reports' ? 10_000.0 : 100.0,
    );

    expect($desired['reports'])->toBeGreaterThan($desired['default']);
});

it('ignores a slow queue that has nothing left to process', function () {
    // Its jobs waited a long time a minute ago; that is history, not demand.
    $desired = app(AutoScaler::class)->desiredPerPool(
        latencyOptions(),
        sizeFor: fn (string $pool) => $pool === 'reports' ? 0 : 40,
        runtimeFor: null,
        waitFor: fn () => 9000.0,
    );

    expect($desired['reports'])->toBe(1)
        ->and($desired['default'])->toBeGreaterThan(1);
});

it('falls back to the old behaviour when no wait reader is supplied', function () {
    // The strategy is opt-in per supervisor; without a reader it must not
    // silently scale everything to the minimum.
    $desired = app(AutoScaler::class)->desiredPerPool(
        latencyOptions(),
        sizeFor: fn () => 100,
        runtimeFor: fn () => 50.0,
        waitFor: null,
    );

    expect(array_sum($desired))->toBe(10);
});

it('measures the wait a queue actually experienced, at the tail', function () {
    // Eight fast jobs and two that waited a minute: the mean (12s) describes
    // nobody, while the p90 reports what the slowest tenth actually got.
    foreach ([100, 100, 100, 100, 100, 100, 100, 100, 60_000, 60_000] as $waited) {
        Run::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => RunType::Job->value,
            'name' => 'App\\Jobs\\Thing',
            'status' => RunStatus::Succeeded->value,
            'connection_name' => 'redis',
            'queue' => 'default',
            'wait_ms' => $waited,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'duration_ms' => 5,
        ]);
    }

    expect(app(QueueWait::class)->for('redis', 'default'))->toBeGreaterThanOrEqual(60_000.0);
});

it('reports no wait for a queue that has not run anything recently', function () {
    expect(app(QueueWait::class)->for('redis', 'idle'))->toBe(0.0);
});
