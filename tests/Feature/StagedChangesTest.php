<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Vigilance\Control\StagedChanges;
use Vigilance\Http\Livewire\StagedChangesBanner;
use Vigilance\Http\Livewire\Workers;
use Vigilance\Models\AuditEntry;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(function () {
    Vigilance::auth(fn () => true);
    config()->set('vigilance.control.staging', true);
    app(StagedChanges::class)->clear();
    app(ControlPlane::class)->reset();
});

it('stages an action instead of firing it', function () {
    Livewire::test(Workers::class)->call('pause');

    expect(app(StagedChanges::class)->count())->toBe(1)
        // The whole point: production is untouched until the batch is applied.
        ->and(app(ControlPlane::class)->isPaused())->toBeFalse();
});

it('fires immediately when staging is off', function () {
    config()->set('vigilance.control.staging', false);

    Livewire::test(Workers::class)->call('pause');

    expect(app(ControlPlane::class)->isPaused())->toBeTrue()
        ->and(app(StagedChanges::class)->count())->toBe(0);
});

it('does not stage the same action twice', function () {
    // Clicking again is a slip, not an intent to pause the fleet twice.
    Livewire::test(Workers::class)->call('pause')->call('pause');

    expect(app(StagedChanges::class)->count())->toBe(1);
});

it('describes a queue action with the queue it targets', function () {
    app(StagedChanges::class)->stage('pause_queue', ['connection' => 'redis', 'queue' => 'reports', 'seconds' => 60]);

    expect(app(StagedChanges::class)->pending()[0]['label'])
        ->toContain('redis:reports')
        ->toContain('60s');
});

it('applies the batch and clears it', function () {
    app(StagedChanges::class)->stage('pause');

    Livewire::test(StagedChangesBanner::class)
        ->assertSee('1 change(s) waiting')
        ->call('apply');

    expect(app(ControlPlane::class)->isPaused())->toBeTrue()
        ->and(app(StagedChanges::class)->count())->toBe(0);
});

it('audits each applied change', function () {
    app(StagedChanges::class)->stage('pause');
    app(StagedChanges::class)->stage('restart');

    app(StagedChanges::class)->apply();

    expect(AuditEntry::query()->where('action', 'like', 'staged:%')->count())->toBe(2);
});

it('lets a single change be dropped from the batch', function () {
    app(StagedChanges::class)->stage('pause');
    app(StagedChanges::class)->stage('restart');

    Livewire::test(StagedChangesBanner::class)->call('discard', 0);

    $pending = app(StagedChanges::class)->pending();

    expect($pending)->toHaveCount(1)
        ->and($pending[0]['action'])->toBe('restart');
});

it('discards the whole batch without applying anything', function () {
    app(StagedChanges::class)->stage('pause');

    Livewire::test(StagedChangesBanner::class)->call('clear');

    expect(app(StagedChanges::class)->count())->toBe(0)
        ->and(app(ControlPlane::class)->isPaused())->toBeFalse();
});

it('refuses an action it does not know', function () {
    app(StagedChanges::class)->stage('rm -rf', ['x' => 1]);

    expect(app(StagedChanges::class)->count())->toBe(0);
});

it('renders nothing when there is nothing waiting', function () {
    Livewire::test(StagedChangesBanner::class)
        ->assertOk()
        ->assertDontSee('waiting to be applied');
});
