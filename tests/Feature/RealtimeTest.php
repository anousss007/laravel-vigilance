<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Vigilance\Events\DashboardChanged;
use Vigilance\Http\Livewire\Runs;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Vigilance::auth(fn () => true);
    Cache::flush();
});

it('polls on a timer by default', function () {
    expect(trim(Blade::render("<div @vigilancePoll('5s')></div>")))
        ->toContain('wire:poll.visible.5s');
});

it('drops back to a slow safety-net poll when realtime is on', function () {
    // Keeping the fast timer as well would mean hitting the database on a
    // schedule AND re-rendering on every broadcast — the opposite of the point.
    // The slow poll stays so a dropped socket cannot leave the page frozen and
    // confidently wrong.
    config()->set('vigilance.realtime.enabled', true);

    $html = Blade::render("<div @vigilancePoll('5s')></div>");

    expect($html)->toContain('wire:poll.visible.120s')
        ->not->toContain('visible.5s');
});

it('subscribes a page to the broadcast only when realtime is on', function () {
    expect(app(Runs::class)->getListeners())->toBe([]);

    config()->set('vigilance.realtime.enabled', true);

    expect(app(Runs::class)->getListeners())
        ->toHaveKey('echo-private:vigilance,.vigilance.changed');
});

it('honours a custom channel name', function () {
    config()->set('vigilance.realtime.enabled', true);
    config()->set('vigilance.realtime.channel', 'ops-vigilance');

    expect(app(Runs::class)->getListeners())
        ->toHaveKey('echo-private:ops-vigilance,.vigilance.changed');
});

it('broadcasts nothing while realtime is off', function () {
    Event::fake([DashboardChanged::class]);

    DashboardChanged::throttled('runs');

    Event::assertNotDispatched(DashboardChanged::class);
});

it('coalesces a burst into one broadcast', function () {
    // A busy queue finishes thousands of jobs a minute and the dashboard only
    // needs to know that some did. Without this, "real time" costs strictly
    // more than the poll it replaces.
    config()->set('vigilance.realtime.enabled', true);
    Event::fake([DashboardChanged::class]);

    foreach (range(1, 50) as $i) {
        DashboardChanged::throttled('runs');
    }

    Event::assertDispatchedTimes(DashboardChanged::class, 1);
});

it('keeps separate topics on separate throttles', function () {
    config()->set('vigilance.realtime.enabled', true);
    Event::fake([DashboardChanged::class]);

    DashboardChanged::throttled('runs');
    DashboardChanged::throttled('issues');

    Event::assertDispatchedTimes(DashboardChanged::class, 2);
});

it('broadcasts on a private channel under the configured name', function () {
    config()->set('vigilance.realtime.channel', 'vigilance');

    $channels = (new DashboardChanged('runs'))->broadcastOn();

    expect($channels[0]->name)->toBe('private-vigilance');
});

it('survives a cache failure without breaking the unit of work', function () {
    config()->set('vigilance.realtime.enabled', true);
    Cache::shouldReceive('add')->andThrow(new RuntimeException('redis down'));

    // The job that triggered this must finish regardless.
    DashboardChanged::throttled('runs');
})->throwsNoExceptions();
