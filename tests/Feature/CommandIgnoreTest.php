<?php

use Vigilance\Support\Defaults;
use Vigilance\Vigilance;

it('ignores long-running daemons even when the published config lists none', function (string $command) {
    // Simulate a config published before these daemons were added to the
    // default list: the code-level baseline must still protect against
    // capturing a daemon as a perpetually-"running" command run.
    config()->set('vigilance.except.commands', []);

    expect(Vigilance::ignoresCommand($command))->toBeTrue();
})->with([
    'octane:start',
    'reverb:start',
    'pulse:work',
    'pulse:check',
    'queue:work',
    'queue:listen',
    'schedule:work',
    'horizon',
    'horizon:snapshot',
    'vigilance:supervise',
]);

it('still records ordinary commands', function () {
    config()->set('vigilance.except.commands', []);

    expect(Vigilance::ignoresCommand('migrate'))->toBeFalse()
        ->and(Vigilance::ignoresCommand('app:import-orders'))->toBeFalse();
});

it('honours user-defined exclusions on top of the daemon baseline', function () {
    config()->set('vigilance.except.commands', ['app:noisy', 'reports:*']);

    expect(Vigilance::ignoresCommand('app:noisy'))->toBeTrue()
        ->and(Vigilance::ignoresCommand('reports:nightly'))->toBeTrue()
        ->and(Vigilance::ignoresCommand('octane:start'))->toBeTrue() // baseline
        ->and(Vigilance::ignoresCommand('migrate'))->toBeFalse();
});

it('treats empty or null command names as ignored', function () {
    expect(Vigilance::ignoresCommand(null))->toBeTrue()
        ->and(Vigilance::ignoresCommand(''))->toBeTrue();
});

it('exposes the daemon baseline through Defaults', function () {
    expect(Defaults::daemonCommands())
        ->toContain('octane:start', 'reverb:start', 'pulse:work', 'queue:work');
});
