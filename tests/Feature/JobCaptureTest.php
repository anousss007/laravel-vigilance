<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;
use Vigilance\Support\Redactor;
use Vigilance\Tests\Fixtures\FailingJob;
use Vigilance\Tests\Fixtures\SampleJob;

uses(RefreshDatabase::class);

it('captures a successful job run with parameters and tags', function () {
    config()->set('queue.default', 'sync');

    SampleJob::dispatch(42, 'invoice');

    $run = Run::query()->where('name', SampleJob::class)->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->type)->toBe(RunType::Job)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->parameters['amount'])->toBe(42)
        ->and($run->parameters['label'])->toBe('invoice');

    // Framework trait plumbing must not leak into the captured parameters.
    expect($run->parameters)->not->toHaveKey('connection')
        ->and($run->parameters)->not->toHaveKey('job')
        ->and($run->parameters)->not->toHaveKey('middleware');

    expect($run->tags)->toContain('sample', 'amount:42');
});

it('redacts secret-looking parameters', function () {
    config()->set('queue.default', 'sync');

    SampleJob::dispatch(1, 'x', 'super-secret');

    $run = Run::query()->where('name', SampleJob::class)->latest('id')->first();

    expect($run->parameters['password'])->toBe(Redactor::PLACEHOLDER);
});

it('records a failed job and groups the failure', function () {
    config()->set('queue.default', 'sync');

    try {
        FailingJob::dispatch();
    } catch (Throwable) {
        // sync driver rethrows; that's expected
    }

    $run = Run::query()->where('name', FailingJob::class)->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(RunStatus::Failed)
        ->and($run->exception_class)->toBe(RuntimeException::class)
        ->and($run->exception_message)->toContain('Boom from FailingJob')
        ->and($run->failure_group_id)->not->toBeNull();

    $group = FailureGroup::find($run->failure_group_id);

    expect($group->occurrences)->toBe(1)
        ->and($group->name)->toBe(FailingJob::class);
});

it('captures queue wait time and cpu usage', function () {
    config()->set('queue.default', 'sync');

    SampleJob::dispatch(3, 'metrics');

    $run = Run::query()->where('name', SampleJob::class)->latest('id')->first();

    expect($run->queued_at)->not->toBeNull()
        ->and($run->started_at)->not->toBeNull()
        ->and($run->wait_ms)->not->toBeNull();

    if (function_exists('getrusage')) {
        expect($run->cpu_time_ms)->not->toBeNull();
    }
});

it('writes nothing for a sampled-out successful job', function () {
    config()->set('queue.default', 'sync');
    config()->set('vigilance.capture.sample_rate', 0.0);

    SampleJob::dispatch(7, 'sampled');

    expect(Run::query()->where('name', SampleJob::class)->count())->toBe(0);
});

it('always captures failures even when sampling is off', function () {
    config()->set('queue.default', 'sync');
    config()->set('vigilance.capture.sample_rate', 0.0);

    try {
        FailingJob::dispatch();
    } catch (Throwable) {
    }

    $run = Run::query()->where('name', FailingJob::class)->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(RunStatus::Failed);
});

it('increments occurrences when the same failure recurs', function () {
    config()->set('queue.default', 'sync');

    foreach (range(1, 3) as $i) {
        try {
            FailingJob::dispatch();
        } catch (Throwable) {
        }
    }

    expect(FailureGroup::query()->count())->toBe(1)
        ->and(FailureGroup::query()->first()->occurrences)->toBe(3);
});
