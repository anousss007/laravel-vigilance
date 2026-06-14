<?php

use Vigilance\Supervision\AutoScaler;
use Vigilance\Supervision\ProvisioningPlan;
use Vigilance\Supervision\Supervisor;
use Vigilance\Supervision\SupervisorOptions;

it('round-trips supervisor options and builds a worker command', function () {
    $options = SupervisorOptions::fromArray([
        'name' => 'supervisor-1',
        'connection' => 'redis',
        'queue' => ['emails', 'default'],
        'balance' => 'auto',
        'max_processes' => 8,
        'memory' => 256,
        'tries' => 3,
        'max_time' => 3600,
    ]);

    expect($options->balancing())->toBeTrue()
        ->and($options->autoScaling())->toBeTrue()
        ->and($options->pools())->toBe(['emails', 'default']);

    expect(SupervisorOptions::fromArray($options->toArray())->toArray())->toBe($options->toArray());

    $cmd = $options->workerCommand('emails');
    expect($cmd)->toContain('queue:work', 'redis', '--queue=emails', '--memory=256', '--tries=3', '--max-time=3600');
});

it('treats a non-balancing supervisor as a single pool', function () {
    $options = SupervisorOptions::fromArray([
        'connection' => 'database',
        'queue' => ['a', 'b'],
        'balance' => false,
    ]);

    expect($options->balancing())->toBeFalse()
        ->and($options->pools())->toBe(['a,b']);
});

it('builds supervisor options from the environment config with defaults merged', function () {
    config()->set('vigilance.defaults', [
        'connection' => 'database',
        'queue' => ['default'],
        'balance' => 'auto',
        'min_processes' => 1,
        'max_processes' => 5,
    ]);
    config()->set('vigilance.environments', [
        'testing' => [
            'supervisor-1' => ['max_processes' => 12, 'queue' => ['high', 'default']],
        ],
        'prod*' => [
            'supervisor-1' => ['max_processes' => 50],
        ],
    ]);

    $plan = ProvisioningPlan::get()->toSupervisorOptions('testing');

    expect($plan)->toHaveKey('supervisor-1');
    expect($plan['supervisor-1']->maxProcesses)->toBe(12)
        ->and($plan['supervisor-1']->connection)->toBe('database')
        ->and($plan['supervisor-1']->queue)->toBe(['high', 'default']);

    // Wildcard environment matching.
    $prod = ProvisioningPlan::get()->toSupervisorOptions('production');
    expect($prod['supervisor-1']->maxProcesses)->toBe(50);
});

it('sizes a non-balancing pool to its backlog within [min,max]', function () {
    $options = SupervisorOptions::fromArray(['queue' => ['default'], 'balance' => false, 'min_processes' => 1, 'max_processes' => 10]);
    $scaler = new AutoScaler;

    expect($scaler->desiredPerPool($options, fn () => 4))->toBe(['default' => 4]);
    expect($scaler->desiredPerPool($options, fn () => 99))->toBe(['default' => 10]); // capped at max
    expect($scaler->desiredPerPool($options, fn () => 0))->toBe(['default' => 1]);  // floored at min
});

it('splits processes evenly for simple balancing', function () {
    $options = SupervisorOptions::fromArray(['queue' => ['a', 'b'], 'balance' => 'simple', 'min_processes' => 1, 'max_processes' => 10]);
    $scaler = new AutoScaler;

    expect($scaler->desiredPerPool($options, fn () => 0))->toBe(['a' => 5, 'b' => 5]);
});

it('distributes processes by load share for auto balancing', function () {
    $options = SupervisorOptions::fromArray([
        'queue' => ['a', 'b'], 'balance' => 'auto', 'auto_scaling_strategy' => 'size',
        'min_processes' => 1, 'max_processes' => 10,
    ]);
    $scaler = new AutoScaler;
    $sizes = ['a' => 30, 'b' => 10];

    $desired = $scaler->desiredPerPool($options, fn ($pool) => $sizes[$pool]);

    expect($desired['a'])->toBeGreaterThan($desired['b'])
        ->and($desired['a'])->toBe(8)
        ->and($desired['b'])->toBe(3);
});

it('throttles scaling by balance_max_shift', function () {
    $options = SupervisorOptions::fromArray(['queue' => ['default'], 'balance' => false, 'min_processes' => 1, 'max_processes' => 10, 'balance_max_shift' => 2]);
    $scaler = new AutoScaler;

    // Backlog of 5 but currently 0 processes → may only add balance_max_shift (2).
    expect($scaler->scale($options, ['default' => 0], fn () => 5))->toBe(['default' => 2]);
});

it('weights pools by time-to-clear under the time strategy', function () {
    $options = SupervisorOptions::fromArray([
        'queue' => ['fast', 'slow'], 'balance' => 'auto', 'auto_scaling_strategy' => 'time',
        'min_processes' => 1, 'max_processes' => 12,
    ]);
    $scaler = new AutoScaler;

    // Equal backlog, but "slow" jobs take 10x longer → it must get more workers.
    $sizes = ['fast' => 20, 'slow' => 20];
    $runtimes = ['fast' => 50.0, 'slow' => 500.0];

    $desired = $scaler->desiredPerPool($options, fn ($p) => $sizes[$p], fn ($p) => $runtimes[$p]);

    expect($desired['slow'])->toBeGreaterThan($desired['fast']);

    // With the SIZE strategy the same equal backlog splits evenly — proving the
    // runtime weighting is actually what moved them apart.
    $sizeOptions = SupervisorOptions::fromArray([
        'queue' => ['fast', 'slow'], 'balance' => 'auto', 'auto_scaling_strategy' => 'size',
        'min_processes' => 1, 'max_processes' => 12,
    ]);
    $bySize = $scaler->desiredPerPool($sizeOptions, fn ($p) => $sizes[$p], fn ($p) => $runtimes[$p]);
    expect($bySize['fast'])->toBe($bySize['slow']);
});

it('applies a nice prefix only on posix when nice is set', function () {
    $none = SupervisorOptions::fromArray(['nice' => 0]);
    expect($none->niceWrapper())->toBe([]);

    $nice = SupervisorOptions::fromArray(['nice' => 10]);
    $wrapper = $nice->niceWrapper();

    if (PHP_OS_FAMILY === 'Windows') {
        expect($wrapper)->toBe([]); // nice is POSIX-only
    } else {
        expect($wrapper)->toBe(['nice', '-n', '10']);
    }
});

it('gates re-scaling by the cooldown window', function () {
    // First evaluation always runs.
    expect(Supervisor::cooldownElapsed(null, 1000, 3))->toBeTrue();
    // Within the cooldown it holds.
    expect(Supervisor::cooldownElapsed(1000, 1002, 3))->toBeFalse();
    // After the cooldown it re-evaluates.
    expect(Supervisor::cooldownElapsed(1000, 1003, 3))->toBeTrue();
});
