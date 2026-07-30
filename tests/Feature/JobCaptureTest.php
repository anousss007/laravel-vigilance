<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Capture\JobCapture;
use Vigilance\Capture\Recorder;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;
use Vigilance\Support\Redactor;
use Vigilance\Tests\Fixtures\FailingJob;
use Vigilance\Tests\Fixtures\SampleJob;
use Vigilance\Tests\Fixtures\WrappedFailingJob;

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

it('does not create a duplicate queued run when the app boots twice', function () {
    config()->set('queue.default', 'sync');

    // Simulate a second full application boot in the same PHP process — Vapor's
    // Octane runtime boots once for config:cache and again for the worker.
    // Queue::$createPayloadCallbacks is process-static, so a naive re-register
    // would stack a second callback and write a duplicate "queued" row (same
    // uuid) per dispatch that never gets updated to a terminal status.
    (new JobCapture(app(Recorder::class)))->register();

    SampleJob::dispatch(5, 'once');

    $runs = Run::query()->where('name', SampleJob::class)->get();

    expect($runs)->toHaveCount(1)
        ->and($runs->first()->status)->toBe(RunStatus::Succeeded);
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

it('records a wrapped job failure by its root cause', function () {
    config()->set('queue.default', 'sync');

    try {
        WrappedFailingJob::dispatch();
    } catch (Throwable) {
        // sync driver rethrows; that's expected
    }

    $run = Run::query()->where('name', WrappedFailingJob::class)->latest('id')->first();

    // The Run and its failure group name the RuntimeException at the bottom of
    // getPrevious(), not the LogicException wrapper.
    expect($run->exception_class)->toBe(RuntimeException::class)
        ->and($run->exception_message)->toBe('the real cause on null')
        ->and($run->exception)->toContain('[root cause] RuntimeException: the real cause on null')
        ->and($run->exception)->toContain('wrapped by');

    $group = FailureGroup::find($run->failure_group_id);

    expect($group->exception_class)->toBe(RuntimeException::class)
        ->and($group->message)->toBe('the real cause on null');
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
