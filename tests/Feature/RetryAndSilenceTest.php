<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Vigilance\Capture\Recorder;
use Vigilance\Control\Exceptions\CannotRetry;
use Vigilance\Control\JobRetrier;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Http\Livewire\RunDetail;
use Vigilance\Http\Livewire\Runs;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;
use Vigilance\Tests\Fixtures\SampleJob;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(fn () => Vigilance::auth(fn () => true));

function failedJobRun(int $amount, ?int $groupId = null): Run
{
    return Run::create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => SampleJob::class,
        'status' => RunStatus::Failed->value,
        'failure_group_id' => $groupId,
        'payload_raw' => serialize(new SampleJob($amount)),
        'finished_at' => now(),
    ]);
}

it('retries every failed job in a group and resolves it', function () {
    config()->set('queue.default', 'sync');

    $group = FailureGroup::create([
        'signature' => 'sig-1', 'type' => 'job', 'name' => SampleJob::class,
        'occurrences' => 2, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    failedJobRun(1, $group->id);
    failedJobRun(2, $group->id);

    $result = app(JobRetrier::class)->retryGroup($group->id, 'admin');

    expect($result)->toBe(['retried' => 2, 'skipped' => 0]);
    expect($group->fresh()->resolved_at)->not->toBeNull();
    expect(Run::query()->where('name', SampleJob::class)->where('status', RunStatus::Succeeded->value)->count())->toBeGreaterThanOrEqual(2);
});

it('retries all failed jobs and resolves open groups', function () {
    config()->set('queue.default', 'sync');

    $group = FailureGroup::create([
        'signature' => 'sig-2', 'type' => 'job', 'name' => SampleJob::class,
        'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    failedJobRun(5, $group->id);

    $result = app(JobRetrier::class)->retryFailed('admin');

    expect($result['retried'])->toBe(1);
    expect(FailureGroup::query()->whereNull('resolved_at')->count())->toBe(0);
});

it('does not dispatch the same failed job twice', function () {
    config()->set('queue.default', 'sync');
    config()->set('vigilance.capture.sample_rate', 0.0);

    $group = FailureGroup::create([
        'signature' => 'sig-once', 'type' => 'job', 'name' => SampleJob::class,
        'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    $run = failedJobRun(7, $group->id);

    $first = app(JobRetrier::class)->retryFailed('admin');
    $succeededAfterFirst = Run::query()->where('status', RunStatus::Succeeded->value)->count();

    expect(fn () => app(JobRetrier::class)->retry($run->id, 'admin'))
        ->toThrow(CannotRetry::class, 'already been retried');

    $second = app(JobRetrier::class)->retryFailed('admin');

    expect($first)->toBe(['retried' => 1, 'skipped' => 0])
        ->and($second)->toBe(['retried' => 0, 'skipped' => 0])
        ->and($run->fresh()->retries)->toHaveCount(1)
        ->and(Run::query()->where('status', RunStatus::Succeeded->value)->count())->toBe($succeededAfterFirst);
});

it('resolves only issues whose eligible jobs were dispatched', function () {
    config()->set('queue.default', 'sync');

    $retried = FailureGroup::create([
        'signature' => 'sig-retried', 'type' => 'job', 'name' => SampleJob::class,
        'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    failedJobRun(1, $retried->id);

    $skipped = FailureGroup::create([
        'signature' => 'sig-skipped', 'type' => 'job', 'name' => SampleJob::class,
        'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    failedJobRun(2, $skipped->id)->forceFill(['payload_raw' => null])->save();

    $unrelated = FailureGroup::create([
        'signature' => 'sig-browser', 'type' => 'browser', 'source' => 'browser',
        'name' => '/checkout', 'occurrences' => 1,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $result = app(JobRetrier::class)->retryFailed('admin');

    expect($result)->toBe(['retried' => 1, 'skipped' => 1])
        ->and($retried->fresh()->resolved_at)->not->toBeNull()
        ->and($skipped->fresh()->resolved_at)->toBeNull()
        ->and($unrelated->fresh()->resolved_at)->toBeNull();
});

it('leaves groups beyond the bulk retry cap open', function () {
    config()->set('queue.default', 'sync');

    $first = FailureGroup::create([
        'signature' => 'sig-cap-1', 'type' => 'job', 'name' => SampleJob::class,
        'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    failedJobRun(1, $first->id);

    $second = FailureGroup::create([
        'signature' => 'sig-cap-2', 'type' => 'job', 'name' => SampleJob::class,
        'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    failedJobRun(2, $second->id);

    $result = app(JobRetrier::class)->retryFailed('admin', cap: 1);

    expect($result)->toBe(['retried' => 1, 'skipped' => 0])
        ->and($first->fresh()->resolved_at)->not->toBeNull()
        ->and($second->fresh()->resolved_at)->toBeNull();
});

it('does not resolve a group when none of its jobs can be restored', function () {
    $group = FailureGroup::create([
        'signature' => 'sig-group-skipped', 'type' => 'job', 'name' => SampleJob::class,
        'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    failedJobRun(1, $group->id)->forceFill(['payload_raw' => null])->save();

    $result = app(JobRetrier::class)->retryGroup($group->id, 'admin');

    expect($result)->toBe(['retried' => 0, 'skipped' => 1])
        ->and($group->fresh()->resolved_at)->toBeNull();
});

it('keeps the retry child even when successful-run sampling is off', function () {
    config()->set('vigilance.capture.sample_rate', 0.0);

    $parent = failedJobRun(3);

    $payload = fn (int $amount) => [
        'uuid' => (string) Str::uuid(),
        'displayName' => SampleJob::class,
        'data' => ['commandName' => SampleJob::class, 'command' => serialize(new SampleJob($amount))],
    ];

    // The retry child IS the marker that its parent was re-dispatched, so it
    // has to survive a sampling rate that drops every other successful run —
    // otherwise the parent stays eligible for the next bulk retry and the same
    // work runs again.
    $ordinary = app(Recorder::class)->onJobPayloadCreate('database', 'default', $payload(1));

    $child = Vigilance::asManual(
        'admin',
        fn () => app(Recorder::class)->onJobPayloadCreate('database', 'default', $payload(3)),
        retryOf: $parent->id,
    );

    expect($ordinary['vigilance_keep'])->toBe(0)
        ->and($child['vigilance_keep'])->toBe(1)
        ->and(Run::query()->where('status', RunStatus::Queued->value)->count())->toBe(1)
        ->and($parent->fresh()->retries)->toHaveCount(1);
});

it('retries a failed job that never got grouped', function () {
    config()->set('queue.default', 'sync');

    $run = failedJobRun(9);

    expect($run->failure_group_id)->toBeNull();

    $result = app(JobRetrier::class)->retryFailed('admin');

    expect($result)->toBe(['retried' => 1, 'skipped' => 0])
        ->and($run->fresh()->retries)->toHaveCount(1);
});

it('leaves failed jobs under an already resolved issue alone', function () {
    config()->set('queue.default', 'sync');

    $resolved = FailureGroup::create([
        'signature' => 'sig-closed', 'type' => 'job', 'name' => SampleJob::class,
        'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        'resolved_at' => now(),
    ]);
    $run = failedJobRun(4, $resolved->id);

    // Bulk retry works through the *open* issues; it is not "re-run every
    // failed row ever recorded". A closed issue stays closed — and the run is
    // still retryable one at a time from its own page.
    expect(app(JobRetrier::class)->retryFailed('admin'))->toBe(['retried' => 0, 'skipped' => 0])
        ->and($run->fresh()->retries)->toHaveCount(0);

    app(JobRetrier::class)->retry($run->id, 'admin');

    expect($run->fresh()->retries)->toHaveCount(1);
});

it('retries nothing when the bulk cap leaves no room', function () {
    config()->set('queue.default', 'sync');

    $run = failedJobRun(6);

    // A cap of zero means none, and a negative cap must not read as
    // "unlimited" — which is exactly what a bare limit() does below zero.
    expect(app(JobRetrier::class)->retryFailed('admin', cap: 0))->toBe(['retried' => 0, 'skipped' => 0])
        ->and(app(JobRetrier::class)->retryFailed('admin', cap: -5))->toBe(['retried' => 0, 'skipped' => 0])
        ->and($run->fresh()->retries)->toHaveCount(0);
});

it('refuses to retry anything that is not a failed job', function () {
    $succeeded = failedJobRun(1);
    $succeeded->forceFill(['status' => RunStatus::Succeeded->value])->save();

    $command = Run::create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Command->value,
        'name' => 'queue:work',
        'status' => RunStatus::Failed->value,
        'finished_at' => now(),
    ]);

    expect(fn () => app(JobRetrier::class)->retry($succeeded->id, 'admin'))
        ->toThrow(CannotRetry::class, 'not in a failed state');

    expect(fn () => app(JobRetrier::class)->retry($command->id, 'admin'))
        ->toThrow(CannotRetry::class, 'is not a job');

    expect(fn () => app(JobRetrier::class)->retry($succeeded->id + 9999, 'admin'))
        ->toThrow(CannotRetry::class, 'not found');
});

it('does not fire an already retried job again from the run page', function () {
    config()->set('queue.default', 'sync');

    $run = failedJobRun(8);
    app(JobRetrier::class)->retry($run->id, 'admin');

    $succeeded = Run::query()->where('status', RunStatus::Succeeded->value)->count();

    // A second press of the button reports the refusal through the page's
    // flash; what must not happen is the work running twice.
    Livewire::test(RunDetail::class, ['run' => $run])
        ->call('retry')
        ->assertOk();

    expect(Run::query()->where('status', RunStatus::Succeeded->value)->count())->toBe($succeeded)
        ->and($run->fresh()->retries)->toHaveCount(1);
});

it('detects silenced runs', function () {
    config()->set('vigilance.silence.jobs', ['App\\Jobs\\Noisy*']);
    config()->set('vigilance.silence.tags', ['heartbeat']);

    expect(Vigilance::isSilenced('App\\Jobs\\NoisyThing'))->toBeTrue()
        ->and(Vigilance::isSilenced('App\\Jobs\\Important'))->toBeFalse()
        ->and(Vigilance::isSilenced('App\\Jobs\\Important', ['heartbeat']))->toBeTrue();
});

it('hides silenced runs from the feed unless toggled', function () {
    config()->set('vigilance.silence.jobs', ['App\\Jobs\\Noisy*']);

    Run::create(['uuid' => (string) Str::uuid(), 'type' => RunType::Job->value, 'name' => 'App\\Jobs\\NoisyHeartbeat', 'status' => RunStatus::Succeeded->value]);
    Run::create(['uuid' => (string) Str::uuid(), 'type' => RunType::Job->value, 'name' => 'App\\Jobs\\ImportantWork', 'status' => RunStatus::Succeeded->value]);

    Livewire::test(Runs::class)
        ->assertSee('ImportantWork')
        ->assertDontSee('NoisyHeartbeat');

    Livewire::test(Runs::class)
        ->set('silenced', true)
        ->assertSee('NoisyHeartbeat');
});
