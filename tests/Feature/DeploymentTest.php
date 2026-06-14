<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Vigilance\Http\Livewire\Overview;
use Vigilance\Models\Deployment;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(fn () => Vigilance::auth(fn () => true));

it('records a deployment marker', function () {
    $this->artisan('vigilance:deploy', ['--release' => 'v2.1.0', '--notes' => 'ship it'])
        ->assertSuccessful();

    $deployment = Deployment::query()->first();

    expect(Deployment::query()->count())->toBe(1)
        ->and($deployment->version)->toBe('v2.1.0')
        ->and($deployment->notes)->toBe('ship it')
        ->and($deployment->environment)->not->toBeNull()
        ->and($deployment->deployed_at)->not->toBeNull();
});

it('shows recent deployments on the overview', function () {
    Deployment::query()->create([
        'version' => 'v2.1.0',
        'environment' => 'production',
        'deployed_at' => now(),
        'created_at' => now(),
    ]);

    Livewire::test(Overview::class)
        ->assertOk()
        ->assertSee('Recent deployments')
        ->assertSee('v2.1.0');
});

it('labels a deployment from version, commit, then id', function () {
    $byVersion = new Deployment(['version' => 'v3', 'commit' => 'abcdef1234']);
    expect($byVersion->label())->toBe('v3');

    $byCommit = new Deployment(['commit' => 'abcdef1234567']);
    expect($byCommit->label())->toBe('abcdef12');
});
