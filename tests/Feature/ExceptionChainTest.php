<?php

use Illuminate\View\ViewException;
use Vigilance\Support\ExceptionChain;

/**
 * Build the exact envelope the feedback describes: a root \Error, re-wrapped by
 * Blade and then by Livewire into nested ViewExceptions, each tacking on a
 * repeated "(View: …)" suffix.
 */
function wrappedViewException(string $rootMessage, int $levels = 3): Throwable
{
    $view = '/app/vendor/filament/tables/resources/views/index.blade.php';
    $e = new Error($rootMessage);

    for ($i = 0; $i < $levels; $i++) {
        $e = new ViewException(
            $rootMessage.str_repeat(" (View: {$view})", $i + 1),
            0,
            1,
            __FILE__,
            __LINE__,
            $e,
        );
    }

    return $e;
}

it('unwinds getPrevious() to the root cause', function () {
    $chain = ExceptionChain::from(wrappedViewException('Call to a member function newQueryWithoutRelationships() on null'));

    expect($chain->isWrapped())->toBeTrue()
        ->and($chain->rootClass())->toBe(Error::class)
        ->and($chain->rootMessage())->toBe('Call to a member function newQueryWithoutRelationships() on null');
});

it('reports a single, unwrapped exception as its own root', function () {
    $chain = ExceptionChain::from(new RuntimeException('plain'));

    expect($chain->isWrapped())->toBeFalse()
        ->and($chain->rootClass())->toBe(RuntimeException::class)
        ->and($chain->rootMessage())->toBe('plain');
});

it('de-noises the repeated "(View: …)" suffix in a message', function () {
    $view = '/app/index.blade.php';

    // Same view repeated three times → collapse to one.
    expect(ExceptionChain::cleanMessage("boom (View: {$view}) (View: {$view}) (View: {$view})"))
        ->toBe("boom (View: {$view})");

    // Distinct views are kept, in order, de-duplicated.
    expect(ExceptionChain::cleanMessage('boom (View: /a.blade.php) (View: /b.blade.php) (View: /a.blade.php)'))
        ->toBe('boom (View: /a.blade.php) (View: /b.blade.php)');

    // No "(View: …)" run → untouched.
    expect(ExceptionChain::cleanMessage('just a message'))->toBe('just a message');
});

it('renders a sample that leads with the root cause and lists the wrappers', function () {
    $chain = ExceptionChain::from(wrappedViewException('Call to a member function newQueryWithoutRelationships() on null'));
    $sample = $chain->sample();

    expect($sample)
        ->toContain('[root cause] Error: Call to a member function newQueryWithoutRelationships() on null')
        ->toContain('wrapped by (outermost first):')
        ->toContain(ViewException::class)
        ->toContain('culprit:')
        // The de-noised wrapper message appears once, not tripled.
        ->and(substr_count($sample, '(View:'))->toBeLessThanOrEqual(3);
});

it('always resolves a culprit, falling back to the root throw location', function () {
    // In the package's own suite every real frame lives under the package dir, so
    // no application frame is found and culprit falls back to the root's own
    // file:line — still more useful than a wrapper's frame 0.
    $chain = ExceptionChain::from(new RuntimeException('x'));

    expect($chain->culprit())->toContain(basename(__FILE__));
});
