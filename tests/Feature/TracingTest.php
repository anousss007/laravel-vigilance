<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Tracing\Contracts\TraceStorage;
use Vigilance\Tracing\Tracer;

uses(RefreshDatabase::class);

function tracer(array $config = []): Tracer
{
    config()->set('vigilance.tracing.enabled', true);
    config()->set('vigilance.tracing.sample_rate', 0);
    config()->set('vigilance.tracing.slow_threshold', 1000);
    config()->set('vigilance.tracing.max_spans', 1000);

    foreach ($config as $key => $value) {
        config()->set('vigilance.tracing.'.$key, $value);
    }

    app()->forgetInstance(Tracer::class);

    return app(Tracer::class);
}

it('does nothing while disabled', function () {
    config()->set('vigilance.tracing.enabled', false);
    app()->forgetInstance(Tracer::class);
    $tracer = app(Tracer::class);

    $tracer->start('request', 'GET /x');
    expect($tracer->sampling())->toBeFalse();
    $tracer->finish('ok');

    expect(app(TraceStorage::class)->recent())->toHaveCount(0);
});

it('persists a slow trace with its spans', function () {
    $tracer = tracer();
    $t0 = microtime(true);

    $tracer->start('request', 'GET /reports', $t0, ['method' => 'GET', 'path' => '/reports']);
    $tracer->span('query', 'select * from orders', $t0 + 0.1, $t0 + 0.6, ['location' => 'OrderController.php:20']);
    $tracer->span('cache', 'get config', $t0 + 0.7, $t0 + 0.7);
    $tracer->finish('ok', $t0 + 2.0); // 2000ms => slow

    $traces = app(TraceStorage::class)->recent();
    expect($traces)->toHaveCount(1);

    $trace = app(TraceStorage::class)->find($traces->first()->id);
    expect($trace->durationMs)->toBe(2000)
        ->and($trace->spanCount)->toBe(2)
        ->and($trace->spans)->toHaveCount(2)
        ->and($trace->spans[0]->type)->toBe('query')
        ->and($trace->spans[0]->offsetUs)->toBe(100000)
        ->and($trace->spans[0]->durationUs)->toBe(500000);
});

it('drops a fast unsampled trace', function () {
    $tracer = tracer();
    $t0 = microtime(true);

    $tracer->start('request', 'GET /fast', $t0);
    $tracer->span('query', 'select 1', $t0, $t0 + 0.001);
    $tracer->finish('ok', $t0 + 0.05); // 50ms, not slow, not sampled

    expect(app(TraceStorage::class)->recent())->toHaveCount(0);
});

it('always keeps an errored trace even when fast', function () {
    $tracer = tracer();
    $t0 = microtime(true);

    $tracer->start('request', 'GET /boom', $t0);
    $tracer->finish('error', $t0 + 0.02);

    expect(app(TraceStorage::class)->recent())->toHaveCount(1)
        ->and(app(TraceStorage::class)->recent()->first()->failed())->toBeTrue();
});

it('keeps a fast trace when head-sampled', function () {
    $tracer = tracer(['sample_rate' => 1]);
    $t0 = microtime(true);

    $tracer->start('request', 'GET /sampled', $t0);
    $tracer->finish('ok', $t0 + 0.02);

    expect(app(TraceStorage::class)->recent())->toHaveCount(1);
});

it('caps spans per trace and counts the overflow', function () {
    $tracer = tracer(['max_spans' => 2]);
    $t0 = microtime(true);

    $tracer->start('request', 'GET /noisy', $t0);
    for ($i = 0; $i < 5; $i++) {
        $tracer->span('query', 'q'.$i, $t0, $t0 + 0.001);
    }
    $tracer->finish('ok', $t0 + 2.0);

    $trace = app(TraceStorage::class)->recent()->first();
    expect($trace->spanCount)->toBe(2)
        ->and($trace->droppedSpans)->toBe(3);
});

it('flags a likely N+1 query pattern', function () {
    $tracer = tracer(['n_plus_one_threshold' => 5]);
    $t0 = microtime(true);

    $tracer->start('request', 'GET /posts', $t0);
    for ($i = 0; $i < 12; $i++) {
        $tracer->span('query', 'select * from comments where post_id = ?', $t0, $t0 + 0.001);
    }
    $tracer->span('query', 'select * from posts', $t0, $t0 + 0.001);
    $tracer->finish('ok', $t0 + 2.0);

    $trace = app(TraceStorage::class)->find(app(TraceStorage::class)->recent()->first()->id);

    expect($trace->attributes['n_plus_one']['count'])->toBe(12)
        ->and($trace->attributes['n_plus_one']['sql'])->toBe('select * from comments where post_id = ?');
});

it('does not flag N+1 below the threshold', function () {
    $tracer = tracer(['n_plus_one_threshold' => 20]);
    $t0 = microtime(true);

    $tracer->start('request', 'GET /ok', $t0);
    for ($i = 0; $i < 5; $i++) {
        $tracer->span('query', 'select 1', $t0, $t0 + 0.001);
    }
    $tracer->finish('ok', $t0 + 2.0);

    $trace = app(TraceStorage::class)->find(app(TraceStorage::class)->recent()->first()->id);
    expect($trace->attributes['n_plus_one'] ?? null)->toBeNull();
});

it('ignores a nested start while a trace is open', function () {
    $tracer = tracer();
    $t0 = microtime(true);

    $tracer->start('request', 'outer', $t0);
    $tracer->start('request', 'inner', $t0); // ignored
    $tracer->finish('ok', $t0 + 2.0);

    expect(app(TraceStorage::class)->recent()->first()->name)->toBe('outer');
});
