<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Entry;
use Vigilance\Metrics\Slo;
use Vigilance\Notifications\Rules\SloBurnRateRule;

uses(RefreshDatabase::class);

function seedRequests(int $total, int $errors): void
{
    $key = (string) json_encode(['GET', '/x']);
    $entries = new Collection;

    for ($i = 0; $i < $total; $i++) {
        $entries->push((new Entry(time(), 'request', $key, 100))->count()->avg()->max());
    }
    for ($i = 0; $i < $errors; $i++) {
        $entries->push((new Entry(time(), 'request_error', $key, 500))->count());
    }

    app(Storage::class)->store($entries);
}

function defineSlo(float $target = 99.9): void
{
    config()->set('vigilance.slos', [
        'avail' => ['name' => 'Availability', 'sli' => 'success_rate', 'target' => $target, 'window_days' => 7],
    ]);
}

it('reports a healthy SLO when within target', function () {
    defineSlo(99.9);
    seedRequests(1000, 0);

    $slo = app(Slo::class)->all()->first();

    expect($slo->current)->toBe(100.0)
        ->and($slo->budgetRemaining)->toBe(100.0)
        ->and($slo->burnRate)->toBe(0.0)
        ->and($slo->status())->toBe('healthy');
});

it('flags a breaching SLO with exhausted budget and high burn rate', function () {
    defineSlo(99.9);
    seedRequests(1000, 50); // 5% errors -> SLI 95%

    $slo = app(Slo::class)->all()->first();

    expect($slo->current)->toBe(95.0)
        ->and($slo->status())->toBe('breaching')
        ->and($slo->budgetRemaining)->toBe(0.0)
        ->and($slo->burnRate)->toBeGreaterThan(1.0);
});

it('reports no-data when there is no traffic', function () {
    defineSlo();

    expect(app(Slo::class)->all()->first()->status())->toBe('no-data');
});

it('fires a burn-rate alert for a breaching SLO', function () {
    defineSlo(99.9);
    seedRequests(1000, 50);

    $alerts = iterator_to_array(app(SloBurnRateRule::class)->evaluate());

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->key)->toBe('slo_burn:avail')
        ->and($alerts[0]->level)->toBe('critical');
});

it('stays quiet for a healthy SLO', function () {
    defineSlo(99.9);
    seedRequests(1000, 0);

    expect(iterator_to_array(app(SloBurnRateRule::class)->evaluate()))->toBeEmpty();
});
