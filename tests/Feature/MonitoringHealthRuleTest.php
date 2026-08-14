<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Notifications\AlertManager;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'queue_long_wait', 'error_rate', 'exception_spike', 'slow_request_rate',
        'scheduled_task_late', 'slo_burn', 'server_resources',
    ] as $rule) {
        config()->set("vigilance.alerts.rules.$rule.enabled", false);
    }
});

function healthAlerts(): array
{
    $alerts = [];
    Vigilance::alertUsing(function ($a) use (&$alerts) {
        $alerts[] = $a;
    });

    app(AlertManager::class)->check();

    return $alerts;
}

function heartbeat(int $agoSeconds): void
{
    app(Apm::class)->set('system', 'web-1', (string) json_encode([
        'name' => 'web-1',
        'cpu' => 5,
        'memory_used' => 100,
        'memory_total' => 8000,
        'storage' => [],
        'updated_at' => time() - $agoSeconds,
    ]));
    app(Apm::class)->ingest();
}

it('stays quiet while a server is still heartbeating', function () {
    heartbeat(30);

    expect(healthAlerts())->toBeEmpty();
});

it('alerts when a server stops reporting', function () {
    heartbeat(3600);

    $alerts = healthAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->title)->toBe('Server stopped reporting to Vigilance')
        ->and($alerts[0]->message)->toContain('web-1')
        // The point of the alert is that its other alerts are now dead too.
        ->toContain('resource alerts can no longer fire')
        ->toContain('vigilance:check');
});

it('alerts when telemetry stops being written despite recent traffic', function () {
    // Written 40 minutes ago: inside the hour that proves the app is in use,
    // but well past the staleness window — the pipeline broke, it is not idle.
    $old = time() - (60 * 40);
    app(Apm::class)->record('request', 'GET /x', 100, $old)->count();
    app(Apm::class)->ingest();

    $alerts = healthAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->title)->toBe('Vigilance stopped recording telemetry')
        ->and($alerts[0]->level)->toBe('critical');
});

it('does not mistake an idle app for a broken pipeline', function () {
    // No telemetry recently AND none before: nobody is using the app. Reporting
    // that as an outage is the classic false positive for this kind of check.
    expect(healthAlerts())->toBeEmpty();
});

it('stays quiet while telemetry is still flowing', function () {
    app(Apm::class)->record('request', 'GET /x', 100)->count();
    app(Apm::class)->ingest();

    expect(healthAlerts())->toBeEmpty();
});

it('records a snapshot heartbeat the rule itself could never report on', function () {
    // MonitoringHealthRule runs from vigilance:snapshot, so it cannot notice the
    // snapshotter dying. This value is what an external check reads instead.
    $this->artisan('vigilance:snapshot')->assertSuccessful();

    // The heartbeat goes through the normal APM buffer, which real runs flush
    // from the console kernel's terminate hook; $this->artisan() never calls
    // terminate, so drain it by hand here.
    app(Apm::class)->ingest();

    $value = app(Storage::class)->values('vigilance')->get('snapshot');

    expect($value)->not->toBeNull()
        ->and(json_decode((string) $value->value, true)['ran_at'])
        ->toBeGreaterThanOrEqual(time() - 5);
})->skip(fn () => ! config('vigilance.metrics.enabled', true), 'metrics disabled');

it('can be switched off entirely', function () {
    config()->set('vigilance.alerts.rules.monitoring_health.enabled', false);

    heartbeat(3600);

    expect(healthAlerts())->toBeEmpty();
});

it('only answers for windows that have a matching bucket period', function () {
    // Buckets are written per DatabaseStorage::periods(). A window with no
    // matching period reads back as zero rather than as an error — the trap
    // that made the first version of the staleness check useless, and the
    // reason it reads the raw entries table instead. 15m is answerable because
    // a period was added for it; 5m is not, and quietly returns nothing.
    app(Apm::class)->record('request', 'GET /x', 100)->count();
    app(Apm::class)->ingest();

    expect(app(Storage::class)->aggregateTotal('request', 'count', CarbonInterval::minutes(15)))->toBeGreaterThan(0.0)
        ->and(app(Storage::class)->aggregateTotal('request', 'count', CarbonInterval::hour()))->toBeGreaterThan(0.0)
        ->and(app(Storage::class)->aggregateTotal('request', 'count', CarbonInterval::minutes(5)))->toBe(0.0);
});
