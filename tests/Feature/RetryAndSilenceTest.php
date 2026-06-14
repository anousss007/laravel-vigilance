<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Vigilance\Control\JobRetrier;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
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
