<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Vigilance\Control\ControlGate;
use Vigilance\Control\Exceptions\CannotRetry;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Control\RunReplayer;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Http\Livewire\RunDetail;
use Vigilance\Models\AuditEntry;
use Vigilance\Models\Run;
use Vigilance\Tests\Fixtures\SampleJob;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Vigilance::auth(fn () => true);
    config()->set('vigilance.control.enabled', true);
    config()->set('vigilance.control.jobs.mode', 'list');
    config()->set('vigilance.control.jobs.allow', [SampleJob::class]);
    config()->set('vigilance.control.commands.mode', 'list');

    // The gate caches its resolved allowlist statically; flush AFTER the config
    // is in place or the cache is rebuilt from the previous test's settings.
    ControlGate::flush();
});

function makeReplayRun(array $attributes = []): Run
{
    return Run::query()->create(array_merge([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => SampleJob::class,
        'status' => RunStatus::Succeeded->value,
        'queue' => 'default',
        'connection_name' => 'redis',
        'payload_raw' => serialize(new SampleJob(5, 'hello')),
        'started_at' => now()->subSeconds(2),
        'finished_at' => now(),
        'duration_ms' => 20,
    ], $attributes));
}

it('re-runs a succeeded job with the payload it ran with', function () {
    Queue::fake();

    $run = makeReplayRun();

    $result = app(RunReplayer::class)->replay($run->id, 'alice');

    expect($result['type'])->toBe('job');
    Queue::assertPushed(SampleJob::class);
});

it('refuses when the control plane is disabled', function () {
    // Re-running is a dispatch, not a recovery — it must never be reachable on
    // a read-only install, however the button got clicked.
    config()->set('vigilance.control.enabled', false);

    $run = makeReplayRun();

    expect(fn () => app(RunReplayer::class)->replay($run->id))
        ->toThrow(NotAllowed::class);
});

it('refuses a job the allowlist does not cover', function () {
    config()->set('vigilance.control.jobs.allow', []);
    ControlGate::flush();

    $run = makeReplayRun();

    expect(fn () => app(RunReplayer::class)->replay($run->id))
        ->toThrow(NotAllowed::class);
});

it('refuses a run that has not finished', function () {
    $run = makeReplayRun(['status' => RunStatus::Running->value, 'finished_at' => null]);

    expect(fn () => app(RunReplayer::class)->replay($run->id))
        ->toThrow(CannotRetry::class);
});

it('audits the re-run against the original', function () {
    Queue::fake();

    $run = makeReplayRun();

    app(RunReplayer::class)->replay($run->id, 'alice');

    $entry = AuditEntry::query()->where('action', 'rerun')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->subject)->toBe(SampleJob::class)
        ->and($entry->user)->toBe('alice');
});

it('offers the button only when the gate would actually allow it', function () {
    $run = makeReplayRun();

    Livewire::test(RunDetail::class, ['run' => $run])
        ->assertSet('runId', $run->id)
        ->assertSee('Re-run');

    config()->set('vigilance.control.enabled', false);

    Livewire::test(RunDetail::class, ['run' => $run])
        ->assertDontSee('Re-run');
});

it('still offers plain retry for a failed job without the control plane', function () {
    // Recovery is not gated the way dispatch is; turning off manual control
    // must not take away the ability to retry a failure.
    config()->set('vigilance.control.enabled', false);

    $run = makeReplayRun(['status' => RunStatus::Failed->value]);

    Livewire::test(RunDetail::class, ['run' => $run])
        ->assertSee('Retry job')
        ->assertDontSee('Re-run');
});
