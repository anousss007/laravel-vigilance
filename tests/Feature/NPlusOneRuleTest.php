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
    config()->set('vigilance.alerts.rules.n_plus_one', ['enabled' => true, 'min_occurrences' => 2, 'window' => '1h']);
});

it('alerts on recurring N+1 patterns', function () {
    app(Apm::class)->record('n_plus_one', 'GET /list', 10)->count()->max();
    app(Apm::class)->record('n_plus_one', 'GET /list', 14)->count()->max();
    app(Apm::class)->ingest();

    $captured = [];
    Vigilance::alertUsing(function ($a) use (&$captured) {
        $captured[] = $a->key;
    });

    expect(app(AlertManager::class)->check())->toBe(1)
        ->and($captured)->toContain('n_plus_one:GET /list');
});

it('stays quiet below the minimum occurrences', function () {
    app(Apm::class)->record('n_plus_one', 'GET /list', 10)->count()->max();
    app(Apm::class)->ingest();

    expect(app(AlertManager::class)->check())->toBe(0);
});
