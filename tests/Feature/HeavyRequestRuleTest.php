<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Notifications\AlertManager;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['queue_long_wait', 'error_rate', 'exception_spike', 'slow_request_rate', 'scheduled_task_late'] as $rule) {
        config()->set("vigilance.alerts.rules.$rule.enabled", false);
    }

    config()->set('vigilance.alerts.rules.heavy_request', [
        'enabled' => true,
        'queries' => 100,
        'memory_mb' => 128,
        'min_requests' => 2,
        'window' => '1h',
    ]);
});

function recordProfile(string $type, string $method, string $path, int $value): void
{
    app(Apm::class)->record($type, (string) json_encode([$method, $path]), $value)->count()->avg()->max();
}

function heavyRequestAlerts(): array
{
    $messages = [];
    Vigilance::alertUsing(function ($a) use (&$messages) {
        $messages[] = $a->message;
    });

    app(AlertManager::class)->check();

    return $messages;
}

it('alerts on a route running too many queries', function () {
    recordProfile('request_queries', 'GET', '/orders', 140);
    recordProfile('request_queries', 'GET', '/orders', 90);
    app(Apm::class)->ingest();

    $messages = heavyRequestAlerts();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('GET /orders')
        ->toContain('140 queries')
        ->toContain('avg 115');
});

it('alerts on a route peaking too high on memory', function () {
    recordProfile('request_memory', 'GET', '/export', 200 * 1024);
    recordProfile('request_memory', 'GET', '/export', 100 * 1024);
    app(Apm::class)->ingest();

    $messages = heavyRequestAlerts();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('GET /export')
        ->toContain('200 MB');
});

it('stays quiet below the thresholds', function () {
    recordProfile('request_queries', 'GET', '/orders', 40);
    recordProfile('request_queries', 'GET', '/orders', 40);
    recordProfile('request_memory', 'GET', '/orders', 8 * 1024);
    recordProfile('request_memory', 'GET', '/orders', 8 * 1024);
    app(Apm::class)->ingest();

    expect(heavyRequestAlerts())->toBeEmpty();
});

it('ignores routes with too little traffic to judge', function () {
    recordProfile('request_queries', 'GET', '/rare', 500);
    app(Apm::class)->ingest();

    expect(heavyRequestAlerts())->toBeEmpty();
});

it('disables a threshold set to zero', function () {
    config()->set('vigilance.alerts.rules.heavy_request.queries', 0);

    recordProfile('request_queries', 'GET', '/orders', 900);
    recordProfile('request_queries', 'GET', '/orders', 900);
    app(Apm::class)->ingest();

    expect(heavyRequestAlerts())->toBeEmpty();
});

it('does nothing when the rule is disabled', function () {
    config()->set('vigilance.alerts.rules.heavy_request.enabled', false);

    recordProfile('request_queries', 'GET', '/orders', 900);
    recordProfile('request_queries', 'GET', '/orders', 900);
    app(Apm::class)->ingest();

    expect(heavyRequestAlerts())->toBeEmpty();
});
