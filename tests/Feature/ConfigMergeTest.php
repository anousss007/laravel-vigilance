<?php

use Vigilance\Apm\Recorders\RequestProfile;
use Vigilance\Apm\Recorders\SlowQueries;
use Vigilance\VigilanceServiceProvider;

/**
 * Laravel's mergeConfigFrom is shallow, so a config/vigilance.php published
 * before a release keeps its own "apm" / "alerts" sub-arrays wholesale and never
 * sees anything added since. The provider merges recursively to close that gap.
 */
it('backfills nested defaults a published config predates', function () {
    // A published config that knows about one recorder and nothing added later.
    config()->set('vigilance', [
        'enabled' => true,
        'apm' => [
            'recorders' => [
                SlowQueries::class => ['enabled' => true, 'threshold' => 250],
            ],
        ],
    ]);

    (new VigilanceServiceProvider(app()))->register();

    $recorders = config('vigilance.apm.recorders');

    expect($recorders)->toHaveKey(RequestProfile::class)          // added since
        ->and($recorders[SlowQueries::class]['threshold'])->toBe(250)  // theirs wins
        ->and(config('vigilance.alerts.rules.heavy_request'))->not->toBeNull();
});

it('lets an explicit value win over the packaged default', function () {
    config()->set('vigilance', [
        'apm' => ['recorders' => [RequestProfile::class => ['enabled' => true, 'models' => false]]],
    ]);

    (new VigilanceServiceProvider(app()))->register();

    expect(config('vigilance.apm.recorders.'.RequestProfile::class.'.enabled'))->toBeTrue()
        ->and(config('vigilance.apm.recorders.'.RequestProfile::class.'.models'))->toBeFalse();
});

it('replaces list-shaped values instead of appending to them', function () {
    config()->set('vigilance', [
        'apm' => ['recorders' => [RequestProfile::class => ['ignore' => ['#^/only-this#']]]],
    ]);

    (new VigilanceServiceProvider(app()))->register();

    // Narrowing a default list must be possible — a recursive merge that
    // appended would leave the packaged patterns in place forever.
    expect(config('vigilance.apm.recorders.'.RequestProfile::class.'.ignore'))->toBe(['#^/only-this#']);
});

it('keeps an emptied list empty', function () {
    config()->set('vigilance', [
        'apm' => ['recorders' => [RequestProfile::class => ['ignore' => []]]],
    ]);

    (new VigilanceServiceProvider(app()))->register();

    expect(config('vigilance.apm.recorders.'.RequestProfile::class.'.ignore'))->toBe([]);
});
