<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Vigilance\Capture\Recorder;
use Vigilance\Tracing\Contracts\TraceStorage;
use Vigilance\Tracing\Tracer;

uses(RefreshDatabase::class);

it('emits a traceparent on outgoing HTTP while a trace is live', function () {
    // Regression guard: the global HTTP request middleware must actually be
    // registered (a method_exists() probe on the Http *facade* is always false
    // and would silently disable this).
    Http::fake(['*' => Http::response(['ok' => true])]);

    $tracer = app(Tracer::class);
    $tracer->start('request', 'GET /outbound');
    Http::get('https://api.example.com/things');
    $tracer->finish('ok');

    $sent = null;
    Http::recorded(function ($request) use (&$sent) {
        $sent = $request->header('traceparent');
    });

    expect($sent)->toBeArray()->not->toBeEmpty()
        ->and($sent[0])->toMatch('/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/');
});

it('does not emit a traceparent when no trace is active', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    Http::get('https://api.example.com/things'); // no trace started

    $sent = 'unset';
    Http::recorded(function ($request) use (&$sent) {
        $sent = $request->header('traceparent');
    });

    expect($sent)->toBe([]); // header absent
});

it('builds a valid W3C traceparent for the in-flight trace', function () {
    $tracer = app(Tracer::class);

    expect($tracer->traceparent())->toBeNull(); // no trace yet

    $tracer->start('job', 'demo');
    $tp = $tracer->traceparent();

    expect($tp)->toMatch('/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/');
    $tracer->finish('ok');
});

it('continues an upstream trace across a start (parent recorded)', function () {
    $tracer = app(Tracer::class);

    // A parent produced by an upstream unit.
    $tracer->start('request', 'GET /up');
    $parentTp = $tracer->traceparent();
    $parentId = $tracer->currentTraceId();
    $tracer->finish('ok');

    // A downstream unit continues from it.
    expect($tracer->continueFrom($parentTp))->toBeTrue();
    $tracer->start('job', 'downstream');
    $tracer->finish('ok');

    $trace = collect(app(TraceStorage::class)->recent())->firstWhere('name', 'downstream');
    $full = app(TraceStorage::class)->find($trace->id);

    expect($full->attributes)->toHaveKey('parent_trace_id')
        ->and($full->attributes['parent_trace_id'])->toBe(str_replace('-', '', $parentId));
});

it('rejects invalid and all-zero traceparents', function () {
    $tracer = app(Tracer::class);

    expect($tracer->continueFrom(null))->toBeFalse()
        ->and($tracer->continueFrom('garbage'))->toBeFalse()
        ->and($tracer->continueFrom('00-'.str_repeat('0', 32).'-'.str_repeat('0', 16).'-01'))->toBeFalse()
        ->and($tracer->continueFrom('00-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-01'))->toBeTrue();
});

it('does not continue when propagation is disabled', function () {
    config()->set('vigilance.tracing.propagation', false);

    expect(app(Tracer::class)->continueFrom('00-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-01'))->toBeFalse();
});

it('continues an incoming HTTP traceparent header end-to-end', function () {
    Route::get('/_dt_probe', fn () => 'ok');

    $trace = '4bf92f3577b34da6a3ce929d0e0e4736';
    $this->get('/_dt_probe', ['traceparent' => "00-{$trace}-00f067aa0ba902b7-01"])->assertOk();

    $row = app(TraceStorage::class)->recent()->first();
    $full = app(TraceStorage::class)->find($row->id);

    expect($full->attributes['parent_trace_id'] ?? null)->toBe($trace);
});

it('injects the trace context onto a dispatched job payload', function () {
    $tracer = app(Tracer::class);
    $tracer->start('request', 'GET /dispatcher');

    $out = app(Recorder::class)->onJobPayloadCreate('database', 'default', [
        'uuid' => 'abc',
        'displayName' => 'App\\Jobs\\Demo',
        'data' => ['commandName' => 'App\\Jobs\\Demo', 'command' => 'x'],
    ]);

    expect($out)->toHaveKey('vigilance_traceparent')
        ->and($out['vigilance_traceparent'])->toMatch('/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/');

    $tracer->finish('ok');
});
