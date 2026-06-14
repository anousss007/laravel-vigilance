<?php

use Illuminate\Support\Facades\Gate;
use Vigilance\Vigilance;

/**
 * Reset the static Vigilance::auth() callback so each test exercises the
 * default Gate-based authorization path (static state would otherwise leak in
 * from other test files that call Vigilance::auth()).
 */
function resetVigilanceAuth(): void
{
    // setAccessible() is unnecessary (and deprecated) since PHP 8.1.
    (new ReflectionProperty(Vigilance::class, 'authUsing'))->setValue(null, null);
}

beforeEach(fn () => resetVigilanceAuth());
afterAll(fn () => resetVigilanceAuth());

it('denies dashboard access by default outside the local environment', function () {
    // Testbench runs in the "testing" environment, so the local-only default denies.
    expect(Vigilance::check(request()))->toBeFalse();
});

it('authorizes through a Gate::before hook — the Horizon-style "admins see everything" rule', function () {
    // The param is nullable so the hook also applies to guests in this test;
    // in a real app the dashboard request carries the authenticated admin.
    Gate::before(fn ($user = null) => true);

    expect(Vigilance::check(request()))->toBeTrue();
});

it('authorizes through a custom viewVigilance gate ability', function () {
    Gate::define('viewVigilance', fn ($user = null) => true);

    expect(Vigilance::check(request()))->toBeTrue();
});

it('lets an explicit Vigilance::auth() callback override the gate', function () {
    Gate::define('viewVigilance', fn ($user = null) => false);
    Vigilance::auth(fn () => true);

    expect(Vigilance::check(request()))->toBeTrue();
});
