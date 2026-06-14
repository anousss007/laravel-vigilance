<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Notifications\AlertManager;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Isolate one rule per test.
    foreach (['queue_long_wait', 'error_rate', 'exception_spike', 'slow_request_rate', 'scheduled_task_late'] as $rule) {
        config()->set("vigilance.alerts.rules.$rule.enabled", false);
    }
});

function makeAlertRun(string $status): void
{
    Run::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\Demo',
        'status' => $status,
    ]);
}

it('fires an error-rate alert and throttles repeats', function () {
    config()->set('vigilance.alerts.rules.error_rate', ['enabled' => true, 'min_runs' => 2, 'percent' => 10]);

    $captured = [];
    Vigilance::alertUsing(function ($alert) use (&$captured) {
        $captured[] = $alert->key;
    });

    makeAlertRun(RunStatus::Succeeded->value);
    makeAlertRun(RunStatus::Succeeded->value);
    makeAlertRun(RunStatus::Failed->value);
    makeAlertRun(RunStatus::Failed->value);

    expect(app(AlertManager::class)->check())->toBe(1)
        ->and($captured)->toContain('error_rate');

    // Throttled on the next cycle.
    expect(app(AlertManager::class)->check())->toBe(0);
});

it('does not fire error-rate below the minimum sample size', function () {
    config()->set('vigilance.alerts.rules.error_rate', ['enabled' => true, 'min_runs' => 50, 'percent' => 10]);

    Vigilance::alertUsing(fn () => null);

    makeAlertRun(RunStatus::Failed->value);

    expect(app(AlertManager::class)->check())->toBe(0);
});

it('fires an exception-spike alert from APM aggregates', function () {
    config()->set('vigilance.alerts.rules.exception_spike', ['enabled' => true, 'count' => 3]);

    $apm = app(Apm::class);
    for ($i = 0; $i < 4; $i++) {
        $apm->record('exception', (string) json_encode(['class' => 'RuntimeException', 'location' => 'x:'.$i]), time())->max()->count();
    }
    $apm->ingest();

    expect((int) round(app(Storage::class)->aggregateTotal('exception', 'count', CarbonInterval::hour())))->toBe(4);

    $captured = [];
    Vigilance::alertUsing(function ($alert) use (&$captured) {
        $captured[] = $alert->key;
    });

    expect(app(AlertManager::class)->check())->toBe(1)
        ->and($captured)->toContain('exception_spike');
});

it('respects the master alerts switch', function () {
    config()->set('vigilance.alerts.enabled', false);
    config()->set('vigilance.alerts.rules.error_rate', ['enabled' => true, 'min_runs' => 1, 'percent' => 1]);

    makeAlertRun(RunStatus::Failed->value);

    expect(app(AlertManager::class)->check())->toBe(0);
});
