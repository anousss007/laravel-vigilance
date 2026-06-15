<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vigilance\Capture\FailureGrouper;
use Vigilance\Metrics\ReleaseHealth;
use Vigilance\Models\Deployment;
use Vigilance\Models\FailureGroup;
use Vigilance\Notifications\Rules\ReleaseHealthRule;

uses(RefreshDatabase::class);

function entryRow(string $type, int $timestamp, int $value = 100): array
{
    $row = ['timestamp' => $timestamp, 'type' => $type, 'key' => 'k', 'key_hash' => md5('k'), 'value' => $value];

    // key_hash is a generated column on MySQL/Postgres (only a plain column on SQLite).
    if (DB::connection()->getDriverName() !== 'sqlite') {
        unset($row['key_hash']);
    }

    return $row;
}

function seedRelease(int $errorsAfter, int $latencyAfter = 100): Deployment
{
    $deployedAt = Carbon::now()->subMinutes(10);

    $deployment = Deployment::query()->create([
        'version' => 'v2.0.0',
        'environment' => 'testing',
        'deployed_at' => $deployedAt,
        'created_at' => Carbon::now(),
    ]);

    $rows = [];
    $beforeAt = $deployedAt->copy()->subMinutes(5)->getTimestamp();
    $afterAt = $deployedAt->copy()->addMinutes(2)->getTimestamp();

    for ($i = 0; $i < 60; $i++) {
        $rows[] = entryRow('request', $beforeAt - $i, 100);        // calm baseline
        $rows[] = entryRow('request', $afterAt + $i, $latencyAfter); // after the deploy
    }
    for ($i = 0; $i < $errorsAfter; $i++) {
        $rows[] = entryRow('request_error', $afterAt + $i, 500);
    }

    DB::table('vigilance_entries')->insert($rows);

    return $deployment->fresh();
}

it('flags a deploy as regressed when error rate jumps', function () {
    $status = app(ReleaseHealth::class)->forDeployment(seedRelease(errorsAfter: 20));

    expect($status->verdict)->toBe('regressed')
        ->and($status->errorRateBefore)->toBe(0.0)
        ->and($status->errorRateAfter)->toBeGreaterThan(5.0);
});

it('flags a deploy as regressed when latency spikes', function () {
    $status = app(ReleaseHealth::class)->forDeployment(seedRelease(errorsAfter: 0, latencyAfter: 400));

    expect($status->verdict)->toBe('regressed')
        ->and($status->latencyDeltaPercent())->toBeGreaterThan(50.0);
});

it('reports a stable deploy as healthy', function () {
    $status = app(ReleaseHealth::class)->forDeployment(seedRelease(errorsAfter: 0, latencyAfter: 105));

    expect($status->verdict)->toBe('healthy');
});

it('returns no-data without enough traffic', function () {
    $deployment = Deployment::query()->create([
        'version' => 'v1', 'environment' => 'testing',
        'deployed_at' => Carbon::now()->subMinutes(5), 'created_at' => Carbon::now(),
    ]);

    expect(app(ReleaseHealth::class)->forDeployment($deployment)->verdict)->toBe('no-data');
});

it('fires a critical deploy-regression alert for a bad release', function () {
    seedRelease(errorsAfter: 25);

    $alerts = collect(app(ReleaseHealthRule::class)->evaluate());

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->level)->toBe('critical')
        ->and($alerts->first()->key)->toStartWith('deploy_regression:')
        ->and($alerts->first()->message)->toContain('rolling back');
});

it('tags issues with the release they were first seen in and regressed in', function () {
    config()->set('vigilance.release', 'v1.0.0');
    $id = app(FailureGrouper::class)->record('job', 'App\\Jobs\\X', 'Exception', 'boom');

    expect(FailureGroup::query()->findOrFail($id)->first_release)->toBe('v1.0.0');

    FailureGroup::query()->whereKey($id)->update(['resolved_at' => now()]);

    config()->set('vigilance.release', 'v1.1.0');
    app(FailureGrouper::class)->record('job', 'App\\Jobs\\X', 'Exception', 'boom'); // regression

    $group = FailureGroup::query()->findOrFail($id);

    expect($group->first_release)->toBe('v1.0.0')
        ->and($group->regressed_release)->toBe('v1.1.0');
});
