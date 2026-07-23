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

function recordNPlusOne(string $name, string $sql, ?string $caller, int $count): void
{
    $key = (string) json_encode(['name' => $name, 'sql' => $sql, 'caller' => $caller]);
    app(Apm::class)->record('n_plus_one', $key, $count)->count()->max();
}

it('alerts on recurring N+1 patterns and details the SQL and code line', function () {
    recordNPlusOne('GET /list', 'select * from items where order_id = ?', 'app/Http/Controllers/OrderController.php:42', 10);
    recordNPlusOne('GET /list', 'select * from items where order_id = ?', 'app/Http/Controllers/OrderController.php:42', 14);
    app(Apm::class)->ingest();

    $messages = [];
    Vigilance::alertUsing(function ($a) use (&$messages) {
        $messages[] = $a->message;
    });

    expect(app(AlertManager::class)->check())->toBe(1);

    expect($messages[0])->toContain('GET /list')
        ->toContain('select * from items where order_id = ?')
        ->toContain('app/Http/Controllers/OrderController.php:42')
        ->toContain('14 times');
});

it('stays quiet below the minimum occurrences', function () {
    recordNPlusOne('GET /list', 'select 1', null, 10);
    app(Apm::class)->ingest();

    expect(app(AlertManager::class)->check())->toBe(0);
});
