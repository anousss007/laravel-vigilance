<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Vigilance\Events\FailureRecorded;
use Vigilance\Tests\Fixtures\FailingJob;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

it('runs the doctor command', function () {
    $this->artisan('vigilance:doctor')->assertSuccessful();
});

it('dispatches a FailureRecorded event the first time a failure is seen', function () {
    config()->set('queue.default', 'sync');

    Event::fake([FailureRecorded::class]);

    try {
        FailingJob::dispatch();
    } catch (Throwable) {
    }

    Event::assertDispatched(FailureRecorded::class, fn (FailureRecorded $e) => $e->isNew === true);
});

it('resolves the acting user through a custom resolver', function () {
    Vigilance::resolveUserUsing(fn () => 'ops@example.com');

    expect(Vigilance::currentUser())->toBe('ops@example.com');

    // reset so the custom resolver does not leak into other tests
    Vigilance::resolveUserUsing(fn () => null);
});

it('reports a custom auth callback as registered', function () {
    Vigilance::auth(fn () => true);

    expect(Vigilance::hasCustomAuth())->toBeTrue();
});
