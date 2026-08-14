<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Events\SharedBeat;
use Vigilance\Notifications\AlertManager;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'queue_long_wait', 'error_rate', 'exception_spike', 'slow_request_rate',
        'scheduled_task_late', 'slo_burn', 'monitoring_health',
    ] as $rule) {
        config()->set("vigilance.alerts.rules.$rule.enabled", false);
    }
});

function recordServer(array $overrides = []): void
{
    $data = array_merge([
        'name' => 'web-1',
        'cpu' => 10,
        'memory_used' => 1000,
        'memory_total' => 8000,
        'storage' => [['directory' => '/', 'used' => 10_000, 'total' => 100_000]],
        'updated_at' => time(),
    ], $overrides);

    app(Apm::class)->set('system', 'web-1', (string) json_encode($data));
    app(Apm::class)->ingest();
}

function alertsFired(): array
{
    $alerts = [];
    Vigilance::alertUsing(function ($a) use (&$alerts) {
        $alerts[] = $a;
    });

    app(AlertManager::class)->check();

    return $alerts;
}

it('stays quiet on a healthy server', function () {
    recordServer();

    expect(alertsFired())->toBeEmpty();
});

it('alerts when CPU is saturated', function () {
    recordServer(['cpu' => 94]);

    $alerts = alertsFired();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->title)->toBe('Server CPU saturated')
        ->and($alerts[0]->message)->toContain('web-1')->toContain('94%')
        ->and($alerts[0]->level)->toBe('warning');
});

it('alerts when memory is nearly exhausted, quoting the real figures', function () {
    recordServer(['memory_used' => 7600, 'memory_total' => 8000]);

    $alerts = alertsFired();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->message)->toContain('95%')
        ->toContain('7600 MB of 8000 MB');
});

it('alerts per disk and reports the free space left', function () {
    recordServer(['storage' => [
        ['directory' => '/', 'used' => 50_000, 'total' => 100_000],
        ['directory' => '/var/log', 'used' => 96_000, 'total' => 100_000],
    ]]);

    $alerts = alertsFired();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->message)->toContain('/var/log')
        ->toContain('96%')
        ->toContain('3.9 GB free');
});

it('escalates to critical when usage is nearly total', function () {
    recordServer(['storage' => [['directory' => '/', 'used' => 99_000, 'total' => 100_000]]]);

    expect(alertsFired()[0]->level)->toBe('critical');
});

it('says nothing about a host where memory detection is unsupported', function () {
    // The recorder degrades to 0/0 rather than throwing on an unknown platform;
    // dividing by that would either crash or report a nonsense percentage.
    recordServer(['memory_used' => 0, 'memory_total' => 0]);

    expect(alertsFired())->toBeEmpty();
});

it('ignores a server whose heartbeat has gone stale', function () {
    // Its numbers are frozen; alerting on them would be worse than silence, and
    // the staleness itself is MonitoringHealthRule's business.
    recordServer(['cpu' => 99, 'updated_at' => time() - 3600]);

    expect(alertsFired())->toBeEmpty();
});

it('disables a threshold set to zero', function () {
    config()->set('vigilance.alerts.rules.server_resources.cpu', 0);

    recordServer(['cpu' => 99]);

    expect(alertsFired())->toBeEmpty();
});

it('records a disk usage trend per volume, not just the latest snapshot', function () {
    // A volume filling up over days used to be invisible: only the current
    // snapshot existed, so there was no series to plot or reason about.
    Event::dispatch(new SharedBeat(CarbonImmutable::now(), 'test-server'));

    app(Apm::class)->ingest();

    // graph(), not aggregate(): bucket-only metrics keep their key in the
    // series index, which is also how the servers card reads cpu/memory.
    $graph = app(Storage::class)->graph(['disk'], 'avg', CarbonInterval::hour());

    expect($graph)->not->toBeEmpty();

    $key = json_decode((string) $graph->keys()->first(), true);

    expect($key)->toBeArray()->toHaveCount(2)
        ->and($key[1])->toBeString();
});

it('records memory as a percentage so hosts of different sizes compare', function () {
    Event::dispatch(new SharedBeat(CarbonImmutable::now(), 'test-server'));

    app(Apm::class)->ingest();

    $graph = app(Storage::class)->graph(['memory_percent'], 'avg', CarbonInterval::hour());

    expect($graph)->not->toBeEmpty();

    $points = collect($graph->first()['memory_percent'] ?? [])->filter(fn ($v) => $v !== null);

    expect($points)->not->toBeEmpty()
        ->and((int) $points->first())->toBeGreaterThanOrEqual(0)
        ->and((int) $points->first())->toBeLessThanOrEqual(100);
});
