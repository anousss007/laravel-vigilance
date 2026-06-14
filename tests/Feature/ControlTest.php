<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Control\CommandReflector;
use Vigilance\Control\ControlGate;
use Vigilance\Control\JobDispatcher;
use Vigilance\Control\JobReflector;
use Vigilance\Control\JobRetrier;
use Vigilance\Control\TypeCoercion;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\AuditEntry;
use Vigilance\Models\Run;
use Vigilance\Tests\Fixtures\FailingJob;
use Vigilance\Tests\Fixtures\HiddenJob;
use Vigilance\Tests\Fixtures\SampleJob;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('queue.default', 'sync');
    ControlGate::flush();
});

it('reflects a job constructor into ordered parameter descriptors', function () {
    $schema = (new JobReflector)->schema(SampleJob::class);

    expect($schema)->toHaveCount(3);

    expect($schema[0])->toMatchArray([
        'name' => 'amount',
        'builtin' => 'int',
        'has_default' => true,
        'default' => 5,
    ]);

    expect($schema[1])->toMatchArray([
        'name' => 'label',
        'builtin' => 'string',
        'has_default' => true,
        'default' => 'hello',
    ]);

    expect($schema[2])->toMatchArray([
        'name' => 'password',
        'builtin' => 'string',
        'nullable' => true,
    ]);
});

it('coerces a submitted string into the declared int type', function () {
    $descriptor = (new JobReflector)->schema(SampleJob::class)[0];

    $coerced = (new TypeCoercion)->coerce('42', $descriptor);

    expect($coerced)->toBe(42);
});

it('falls back to the default when an optional value is empty', function () {
    $descriptor = (new JobReflector)->schema(SampleJob::class)[1];

    expect((new TypeCoercion)->coerce('', $descriptor))->toBe('hello');
});

it('dispatches an allowed job synchronously and attributes it to the user', function () {
    config()->set('vigilance.control.jobs', [
        'mode' => 'list',
        'paths' => [],
        'allow' => [SampleJob::class],
        'deny' => [],
    ]);
    ControlGate::flush();

    (new JobDispatcher)->dispatch(
        SampleJob::class,
        ['amount' => '42', 'label' => 'x'],
        queued: false,
        user: 'admin@test',
    );

    $run = Run::query()->where('name', SampleJob::class)->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->type)->toBe(RunType::Job)
        ->and($run->via)->toBe('manual')
        ->and($run->caused_by)->toBe('admin@test')
        ->and($run->parameters['amount'])->toBe(42)
        ->and($run->parameters['label'])->toBe('x');

    $audit = AuditEntry::query()->where('action', 'dispatch_job')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->subject)->toBe(SampleJob::class)
        ->and($audit->user)->toBe('admin@test');
});

it('reflects a command definition into arguments and options', function () {
    $schema = (new CommandReflector)->schema('migrate');

    expect($schema)->toHaveKeys(['arguments', 'options'])
        ->and($schema['options'])->not->toBeEmpty();

    $optionNames = array_column($schema['options'], 'name');

    expect($optionNames)->toContain('force');
});

it('respects the command deny list even in "all" mode', function () {
    config()->set('vigilance.control.commands', [
        'mode' => 'all',
        'allow' => [],
        'deny' => ['migrate:fresh'],
    ]);
    ControlGate::flush();

    $gate = new ControlGate;

    expect($gate->isCommandAllowed('migrate:fresh'))->toBeFalse()
        ->and($gate->isCommandAllowed('migrate'))->toBeTrue();
});

it('discovers marker jobs in the configured paths', function () {
    config()->set('vigilance.control.jobs', [
        'mode' => 'marker',
        'paths' => [dirname(__DIR__).'/Fixtures'],
        'allow' => [],
        'deny' => [],
    ]);
    ControlGate::flush();

    $gate = new ControlGate;

    // SampleJob implements the Vigilance Dispatchable marker; FailingJob does not.
    expect($gate->isJobAllowed(SampleJob::class))->toBeTrue()
        ->and($gate->isJobAllowed(FailingJob::class))->toBeFalse();
});

it('hides jobs marked ShouldNotBeDispatchedManually even under discover mode', function () {
    config()->set('vigilance.control.jobs', [
        'mode' => 'discover',
        'paths' => [dirname(__DIR__).'/Fixtures'],
        'allow' => [],
        'deny' => [],
    ]);
    ControlGate::flush();

    $gate = new ControlGate;

    // discover surfaces every ShouldQueue job (SampleJob, FailingJob)…
    expect($gate->isJobAllowed(SampleJob::class))->toBeTrue()
        ->and($gate->isJobAllowed(FailingJob::class))->toBeTrue()
        // …except those opting out via the marker.
        ->and($gate->isJobAllowed(HiddenJob::class))->toBeFalse();
});

it('explains allowlisted commands dropped by deny or own-command rules', function () {
    config()->set('vigilance.control.commands', [
        'mode' => 'list',
        'allow' => ['migrate', 'migrate:fresh', 'vigilance:doctor'],
        'deny' => ['migrate:fresh'],
    ]);
    ControlGate::flush();

    $dropped = (new ControlGate)->droppedCommands();

    expect($dropped)->toHaveKey('migrate:fresh')
        ->and($dropped['migrate:fresh'])->toBe('denied')
        ->and($dropped)->toHaveKey('vigilance:doctor')
        ->and($dropped['vigilance:doctor'])->toBe('vigilance command')
        ->and($dropped)->not->toHaveKey('migrate'); // genuinely allowed
});

it('retries a failed job from its stored payload and audits it', function () {
    // Produce a real run by dispatching the job synchronously.
    config()->set('vigilance.control.jobs', [
        'mode' => 'list',
        'paths' => [],
        'allow' => [SampleJob::class],
        'deny' => [],
    ]);
    ControlGate::flush();

    (new JobDispatcher)->dispatch(SampleJob::class, ['amount' => '1'], queued: false, user: 'admin@test');

    $run = Run::query()->where('name', SampleJob::class)->latest('id')->first();

    // Mark it failed with a genuine serialized job as the retry payload.
    $run->forceFill([
        'status' => RunStatus::Failed->value,
        'payload_raw' => serialize(new SampleJob(1)),
    ])->save();

    (new JobRetrier)->retry($run->id, user: 'admin@test');

    $audit = AuditEntry::query()->where('action', 'retry')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->subject)->toBe(SampleJob::class)
        ->and($audit->run_id)->toBe($run->id)
        ->and($audit->user)->toBe('admin@test');

    // The retry re-dispatched (sync) the job, producing another succeeded run.
    expect(Run::query()->where('name', SampleJob::class)->where('status', RunStatus::Succeeded->value)->count())
        ->toBeGreaterThanOrEqual(1);
});
