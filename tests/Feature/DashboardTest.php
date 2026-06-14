<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Vigilance\Control\ControlGate;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Http\Livewire\Dispatcher;
use Vigilance\Http\Livewire\Failures;
use Vigilance\Http\Livewire\Overview;
use Vigilance\Http\Livewire\Runs;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;
use Vigilance\Tests\Fixtures\SampleJob;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('queue.default', 'sync');
    Vigilance::auth(fn () => true);
    ControlGate::flush();
});

function makeDashboardRun(array $attributes = []): Run
{
    return Run::query()->create(array_merge([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\ExampleJob',
        'status' => RunStatus::Succeeded->value,
        'queue' => 'default',
        'connection_name' => 'redis',
        'started_at' => now()->subSeconds(2),
        'finished_at' => now(),
        'duration_ms' => 1200,
    ], $attributes));
}

it('returns 200 on the overview route when authorized', function () {
    Vigilance::auth(fn () => true);

    $this->get(route('vigilance.overview'))->assertOk();
});

it('returns 403 on the overview route when not authorized', function () {
    Vigilance::auth(fn () => false);

    $this->get(route('vigilance.overview'))->assertForbidden();
});

it('renders the overview component', function () {
    makeDashboardRun();
    makeDashboardRun(['status' => RunStatus::Failed->value, 'exception_class' => 'RuntimeException']);

    Livewire::test(Overview::class)->assertOk();
});

it('filters runs by status', function () {
    makeDashboardRun(['name' => 'GoodJob', 'status' => RunStatus::Succeeded->value]);
    makeDashboardRun(['name' => 'BadJob', 'status' => RunStatus::Failed->value]);

    Livewire::test(Runs::class)
        ->set('status', RunStatus::Failed->value)
        ->assertSee('BadJob')
        ->assertDontSee('GoodJob');
});

it('lists allowed jobs and dispatches one synchronously', function () {
    config()->set('vigilance.control.enabled', true);
    config()->set('vigilance.control.jobs', [
        'mode' => 'marker',
        'paths' => [dirname(__DIR__).'/Fixtures'],
        'allow' => [],
        'deny' => [],
    ]);
    ControlGate::flush();

    Livewire::test(Dispatcher::class)
        ->assertSee('SampleJob')
        ->set('jobClass', SampleJob::class)
        ->set('values.amount', '7')
        ->set('values.label', 'hi')
        ->set('sync', true)
        ->call('dispatchJob');

    $run = Run::query()->where('name', SampleJob::class)->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->via)->toBe('manual')
        ->and($run->type)->toBe(RunType::Job)
        ->and($run->parameters['amount'])->toBe(7);
});

it('resolves a failure group', function () {
    $group = FailureGroup::query()->create([
        'signature' => str_repeat('a', 64),
        'name' => 'App\\Jobs\\ExampleJob',
        'exception_class' => 'RuntimeException',
        'message' => 'boom',
        'occurrences' => 3,
        'first_seen_at' => now()->subHour(),
        'last_seen_at' => now(),
    ]);

    Livewire::test(Failures::class)
        ->call('resolve', $group->id);

    expect($group->fresh()->resolved_at)->not->toBeNull();
});
