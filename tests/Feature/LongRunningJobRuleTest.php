<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Notifications\AlertManager;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['queue_long_wait', 'error_rate', 'exception_spike', 'slow_request_rate', 'scheduled_task_late'] as $rule) {
        config()->set("vigilance.alerts.rules.$rule.enabled", false);
    }
    config()->set('vigilance.alerts.rules.long_running_job', ['enabled' => true, 'seconds' => 300, 'limit' => 10]);
});

function runningJob(int $agoSeconds): Run
{
    return Run::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\SlowReport',
        'status' => RunStatus::Running->value,
        'queue' => 'reports',
        'connection_name' => 'redis',
        'started_at' => Carbon::now()->subSeconds($agoSeconds),
    ]);
}

it('alerts on a job stuck running past the threshold', function () {
    runningJob(600); // 10 min, over the 5 min threshold

    $captured = [];
    Vigilance::alertUsing(function ($a) use (&$captured) {
        $captured[] = $a->key;
    });

    expect(app(AlertManager::class)->check())->toBe(1)
        ->and($captured[0])->toStartWith('long_running_job:');
});

it('does not alert on a job within the threshold or already finished', function () {
    runningJob(60); // recent
    Run::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\Done',
        'status' => RunStatus::Succeeded->value,
        'started_at' => Carbon::now()->subSeconds(900),
        'finished_at' => Carbon::now(),
    ]);

    expect(app(AlertManager::class)->check())->toBe(0);
});

it('is off unless enabled', function () {
    config()->set('vigilance.alerts.rules.long_running_job.enabled', false);
    runningJob(600);

    expect(app(AlertManager::class)->check())->toBe(0);
});
