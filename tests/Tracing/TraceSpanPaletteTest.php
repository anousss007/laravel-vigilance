<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Mail;
use Vigilance\Tracing\Contracts\TraceStorage;
use Vigilance\Tracing\Tracer;

uses(RefreshDatabase::class);

it('records mail and notification spans inside the active trace', function () {
    config()->set('mail.default', 'array');

    $tracer = app(Tracer::class);
    $t0 = microtime(true);

    $tracer->start('request', 'GET /invoice', $t0);

    Mail::raw('Your invoice', fn ($m) => $m->to('alice@acme.test')->subject('Invoice'));
    event(new NotificationSent((object) [], (object) [], 'slack'));

    $tracer->finish('ok', $t0 + 2.0);

    $trace = app(TraceStorage::class)->find(app(TraceStorage::class)->recent()->first()->id);
    $types = collect($trace->spans)->pluck('type')->all();

    expect($types)->toContain('mail')
        ->and($types)->toContain('notification');
});
