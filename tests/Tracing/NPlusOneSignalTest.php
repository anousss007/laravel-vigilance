<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Tracing\Tracer;

uses(RefreshDatabase::class);

it('promotes a detected N+1 to an APM signal carrying the SQL and code location', function () {
    config()->set('vigilance.tracing.n_plus_one_threshold', 3);

    $tracer = app(Tracer::class);
    $tracer->start('request', 'GET /list');

    $t = microtime(true);
    for ($i = 0; $i < 4; $i++) {
        $tracer->span('query', 'select * from users where id = ?', $t, $t + 0.001, [
            'caller' => 'app/Http/Controllers/OrderController.php:42',
        ]);
    }
    $tracer->finish('ok');

    app(Apm::class)->ingest();

    $row = app(Storage::class)->aggregate('n_plus_one', ['count', 'max'], CarbonInterval::hour())->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->max)->toBe(4);

    // The key encodes route + exact SQL + the app line so the incident is actionable.
    $decoded = json_decode((string) $row->key, true);
    expect($decoded['name'])->toBe('GET /list')
        ->and($decoded['sql'])->toBe('select * from users where id = ?')
        ->and($decoded['caller'])->toBe('app/Http/Controllers/OrderController.php:42');
});

it('does not record an N+1 signal below the threshold', function () {
    config()->set('vigilance.tracing.n_plus_one_threshold', 10);

    $tracer = app(Tracer::class);
    $tracer->start('request', 'GET /ok');
    $t = microtime(true);
    for ($i = 0; $i < 3; $i++) {
        $tracer->span('query', 'select 1', $t, $t + 0.001);
    }
    $tracer->finish('ok');

    app(Apm::class)->ingest();

    expect(app(Storage::class)->aggregate('n_plus_one', ['count'], CarbonInterval::hour()))->toBeEmpty();
});
