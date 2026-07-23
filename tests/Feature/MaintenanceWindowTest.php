<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Notifications\AlertManager;
use Vigilance\Notifications\MaintenanceWindow;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::store()->forget('vigilance:maintenance:until');
    config()->set('vigilance.notifications.maintenance', []);
    foreach (['queue_long_wait', 'error_rate', 'exception_spike', 'slow_request_rate', 'scheduled_task_late'] as $rule) {
        config()->set("vigilance.alerts.rules.$rule.enabled", false);
    }
});

afterEach(fn () => Carbon::setTestNow());

function seedErrorRun(string $status): void
{
    Run::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\Demo',
        'status' => $status,
    ]);
}

it('opens and closes an ad-hoc window', function () {
    $m = app(MaintenanceWindow::class);

    expect($m->active())->toBeFalse();

    $until = $m->start(30);
    expect($m->active())->toBeTrue()
        ->and($m->adHocUntil())->toBe($until)
        ->and($until)->toBeGreaterThan(time());

    $m->stop();
    expect($m->active())->toBeFalse()
        ->and($m->adHocUntil())->toBeNull();
});

it('auto-expires a lapsed ad-hoc window', function () {
    Cache::store()->forever('vigilance:maintenance:until', time() - 5);

    expect(app(MaintenanceWindow::class)->active())->toBeFalse();
});

it('matches a recurring config window (incl. midnight wrap)', function () {
    config()->set('vigilance.notifications.maintenance', [
        ['days' => ['sun'], 'from' => '02:00', 'to' => '03:00'],
        ['from' => '23:00', 'to' => '01:00'], // wraps midnight, every day
    ]);

    $m = app(MaintenanceWindow::class);

    Carbon::setTestNow(Carbon::parse('2026-07-19 02:30:00')); // Sunday
    expect($m->active())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-20 02:30:00')); // Monday, outside sun window
    expect($m->active())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-07-20 23:30:00')); // inside wrap window
    expect($m->active())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-20 00:30:00')); // still inside wrap (after midnight)
    expect($m->active())->toBeTrue();
});

it('suppresses alert notifications while a window is active', function () {
    config()->set('vigilance.alerts.rules.error_rate', ['enabled' => true, 'min_runs' => 2, 'percent' => 10]);

    $captured = [];
    Vigilance::alertUsing(function ($alert) use (&$captured) {
        $captured[] = $alert->key;
    });

    seedErrorRun(RunStatus::Succeeded->value);
    seedErrorRun(RunStatus::Failed->value);
    seedErrorRun(RunStatus::Failed->value);

    // Window open → nothing pages.
    app(MaintenanceWindow::class)->start(30);
    expect(app(AlertManager::class)->check())->toBe(0)
        ->and($captured)->toBe([]);

    // Window closed → the still-breaching condition notifies.
    app(MaintenanceWindow::class)->stop();
    expect(app(AlertManager::class)->check())->toBe(1)
        ->and($captured)->toContain('error_rate');
});

it('toggles maintenance from the artisan command', function () {
    $this->artisan('vigilance:maintenance', ['--minutes' => 10])->assertSuccessful();
    expect(app(MaintenanceWindow::class)->active())->toBeTrue();

    $this->artisan('vigilance:maintenance', ['--status' => true])->assertSuccessful();

    $this->artisan('vigilance:maintenance', ['--off' => true])->assertSuccessful();
    expect(app(MaintenanceWindow::class)->active())->toBeFalse();
});
