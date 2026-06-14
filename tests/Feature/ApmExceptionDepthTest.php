<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

function depthTotal(string $type): float
{
    return app(Storage::class)->aggregateTotal($type, 'count', CarbonInterval::hours(1));
}

it('records logs at or above the configured level only', function () {
    Log::warning('disk filling up');
    Log::error('payment failed');
    Log::info('routine, ignored');
    Log::debug('verbose, ignored');

    app(Apm::class)->ingest();

    expect(depthTotal('log'))->toBe(2.0);

    $rows = app(Storage::class)->aggregate('log', ['count'], CarbonInterval::hours(1));
    expect($rows->pluck('key')->all())->toContain('warning', 'error');
});

it('records notifications by channel', function () {
    Event::dispatch(new NotificationSent((object) [], (object) [], 'slack'));
    Event::dispatch(new NotificationSent((object) [], (object) [], 'mail'));

    app(Apm::class)->ingest();

    expect(depthTotal('notification'))->toBe(2.0);
});

it('records sent mail by recipient', function () {
    config()->set('mail.default', 'array');

    Mail::raw('Hello there', fn ($m) => $m->to('alice@acme.test')->subject('Hi'));

    app(Apm::class)->ingest();

    expect(depthTotal('mail'))->toBe(1.0);

    $rows = app(Storage::class)->aggregate('mail', ['count'], CarbonInterval::hours(1));
    expect($rows->first()->key)->toBe('alice@acme.test');
});

it('records exceptions reported via Vigilance::report()', function () {
    Vigilance::report(new RuntimeException('caught but worth tracking'));

    app(Apm::class)->ingest();

    expect(depthTotal('exception'))->toBe(1.0);

    $rows = app(Storage::class)->aggregate('exception', ['count'], CarbonInterval::hours(1));
    expect(json_decode($rows->first()->key, true)['class'])->toBe(RuntimeException::class);
});
