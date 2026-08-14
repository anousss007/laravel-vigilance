<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Vigilance\Http\Livewire\IncidentModeBanner;
use Vigilance\Support\IncidentMode;
use Vigilance\Tracing\Sampling\Sampler;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Vigilance::auth(fn () => true);
    IncidentMode::flushState();
    config()->set('vigilance.incident_mode.enabled', true);
});

afterEach(function () {
    IncidentMode::disengage();
});

it('is off until engaged', function () {
    expect(IncidentMode::active())->toBeFalse();
});

it('engages for a bounded duration and reports the time left', function () {
    IncidentMode::engage(30, 'alice');

    expect(IncidentMode::active())->toBeTrue()
        ->and(IncidentMode::secondsRemaining())->toBeGreaterThan(29 * 60)
        ->and(IncidentMode::state()['by'])->toBe('alice');
});

it('caps the duration so "briefly" stays true', function () {
    config()->set('vigilance.incident_mode.max_minutes', 60);

    $result = IncidentMode::engage(100_000);

    expect($result['minutes'])->toBe(60);
});

it('expires on its own with nothing scheduled to expire it', function () {
    // The cache TTL *is* the timer: no job, no cron, nothing to run. Even a
    // store with coarse expiry is caught by the explicit until check.
    Cache::put(IncidentMode::CACHE_KEY, ['until' => time() - 1, 'by' => null], now()->addHour());
    IncidentMode::flushState();

    expect(IncidentMode::active())->toBeFalse()
        ->and(Cache::get(IncidentMode::CACHE_KEY))->toBeNull();
});

it('overrides capture sampling while engaged', function () {
    config()->set('vigilance.capture.sample_rate', 0.0);

    expect(Vigilance::passesSampling())->toBeFalse();

    IncidentMode::engage(5);

    expect(Vigilance::passesSampling())->toBeTrue();
});

it('keeps every trace while engaged, even with tracing sampled to zero', function () {
    config()->set('vigilance.tracing.sample_rate', 0);

    expect(app(Sampler::class)->shouldSample('request'))->toBeFalse();

    IncidentMode::engage(5);

    expect(app(Sampler::class)->shouldSample('request'))->toBeTrue();
});

it('does nothing at all when the capability is not enabled', function () {
    // Opt-in: with the flag off it must not even read the cache, so an app that
    // never wants this pays nothing for it.
    config()->set('vigilance.incident_mode.enabled', false);
    Cache::put(IncidentMode::CACHE_KEY, ['until' => time() + 600, 'by' => null], now()->addHour());
    IncidentMode::flushState();

    expect(IncidentMode::active())->toBeFalse();
});

it('degrades to inactive when the cache is unavailable', function () {
    // Monitoring must never take out the request it observes; the safe answer
    // to "is the override on?" when the store is down is no.
    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
    IncidentMode::flushState();

    expect(IncidentMode::active())->toBeFalse();
});

it('memoises the lookup so it costs at most one cache read per request', function () {
    Cache::spy();
    IncidentMode::flushState();

    IncidentMode::active();
    IncidentMode::active();
    IncidentMode::active();

    Cache::shouldHaveReceived('get')->once();
});

it('engages and disengages from the dashboard shell', function () {
    Livewire::test(IncidentModeBanner::class)
        ->assertSee('Incident mode')
        ->set('minutes', 10)
        ->call('engage');

    expect(IncidentMode::active())->toBeTrue();

    Livewire::test(IncidentModeBanner::class)
        // Compact in the topbar: "10m left", not a full-width alert that would
        // stretch the shell's flex row on every page.
        ->assertSee('m left')
        ->call('disengage');

    expect(IncidentMode::active())->toBeFalse();
});

it('renders nothing when the capability is disabled', function () {
    config()->set('vigilance.incident_mode.enabled', false);

    Livewire::test(IncidentModeBanner::class)
        ->assertOk()
        ->assertDontSee('Incident mode');
});
