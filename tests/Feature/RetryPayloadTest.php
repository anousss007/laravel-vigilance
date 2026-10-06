<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Vigilance\Control\Exceptions\CannotRetry;
use Vigilance\Control\JobRetrier;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Support\PayloadSignature;
use Vigilance\Tests\Fixtures\NestedJob;
use Vigilance\Tests\Fixtures\SampleJob;
use Vigilance\Tests\Fixtures\SampleNotification;

uses(RefreshDatabase::class);

function retryRunFor(string $class, ?string $payload): Run
{
    return Run::create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => $class,
        'status' => RunStatus::Failed->value,
        'payload_raw' => $payload,
        'finished_at' => now(),
    ]);
}

function nestedJob(): NestedJob
{
    return new NestedJob(collect([1, 2, 3]), Carbon::parse('2026-10-06 12:00:00'), RunStatus::Failed);
}

it('signs the payload it captures for retry', function () {
    config()->set('queue.default', 'sync');

    SampleJob::dispatch(42, 'invoice');

    $stored = Run::query()->where('name', SampleJob::class)->latest('id')->value('payload_raw');

    expect(PayloadSignature::isSigned($stored))->toBeTrue()
        ->and(unserialize(PayloadSignature::verify($stored)))->toBeInstanceOf(SampleJob::class);
});

it('restores the nested objects of a signed payload', function () {
    $run = retryRunFor(NestedJob::class, PayloadSignature::sign(serialize(nestedJob())));

    $job = app(JobRetrier::class)->restore($run);

    expect($job->items->all())->toBe([1, 2, 3])
        ->and($job->due->toDateTimeString())->toBe('2026-10-06 12:00:00')
        ->and($job->status)->toBe(RunStatus::Failed);
});

it('restores a queued notification so it can be dispatched again', function () {
    $payload = serialize(new SendQueuedNotifications(
        collect([(new AnonymousNotifiable)->route('mail', 'ops@example.com')]),
        new SampleNotification('invoice'),
        ['mail'],
    ));

    $run = retryRunFor(SendQueuedNotifications::class, PayloadSignature::sign($payload));

    $job = app(JobRetrier::class)->restore($run);

    expect($job->notification)->toBeInstanceOf(SampleNotification::class)
        ->and($job->backoff())->toBe(30);
});

it('retries a failed job with nested objects end to end', function () {
    Queue::fake();

    $run = retryRunFor(NestedJob::class, PayloadSignature::sign(serialize(nestedJob())));

    app(JobRetrier::class)->retry($run->id, 'admin');

    Queue::assertPushed(NestedJob::class, fn (NestedJob $job) => $job->items->all() === [1, 2, 3]);
});

it('refuses a signed payload that was modified after capture', function () {
    $signed = PayloadSignature::sign(serialize(new SampleJob(5)));
    $tampered = str_replace('i:5;', 'i:6;', $signed);

    $run = retryRunFor(SampleJob::class, $tampered);

    expect(fn () => app(JobRetrier::class)->restore($run))
        ->toThrow(CannotRetry::class, 'signature check');
});

it('refuses a payload signed with a key that is no longer configured', function () {
    $run = retryRunFor(SampleJob::class, PayloadSignature::sign(serialize(new SampleJob(5))));

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    expect(fn () => app(JobRetrier::class)->restore($run))
        ->toThrow(CannotRetry::class, 'signature check');
});

it('still verifies a payload signed with a previous application key', function () {
    $old = config('app.key');
    $run = retryRunFor(SampleJob::class, PayloadSignature::sign(serialize(new SampleJob(5))));

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('app.previous_keys', [$old]);

    expect(app(JobRetrier::class)->restore($run)->amount)->toBe(5);
});

it('keeps restoring unsigned scalar-only payloads captured before signing', function () {
    $run = retryRunFor(SampleJob::class, serialize(new SampleJob(7)));

    expect(app(JobRetrier::class)->restore($run)->amount)->toBe(7);
});

it('refuses an unsigned payload whose nested objects cannot be restored', function () {
    $run = retryRunFor(NestedJob::class, serialize(nestedJob()));

    expect(fn () => app(JobRetrier::class)->restore($run))
        ->toThrow(CannotRetry::class, 'captured before retry payloads were signed');
});

it('refuses a forged payload that only claims to be signed', function () {
    $run = retryRunFor(SampleJob::class, 'vgl1:'.str_repeat('0', 64).':'.serialize(new SampleJob(5)));

    expect(fn () => app(JobRetrier::class)->restore($run))
        ->toThrow(CannotRetry::class, 'signature check');
});

it('refuses an unsigned payload whose untyped nested objects come back incomplete', function () {
    $payload = serialize(new SendQueuedNotifications(
        collect([(new AnonymousNotifiable)->route('mail', 'ops@example.com')]),
        new SampleNotification,
        ['mail'],
    ));

    $run = retryRunFor(SendQueuedNotifications::class, $payload);

    expect(fn () => app(JobRetrier::class)->restore($run))
        ->toThrow(CannotRetry::class, 'captured before retry payloads were signed');
});
