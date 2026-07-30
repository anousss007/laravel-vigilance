<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ViewException;
use Vigilance\Capture\IssueCapture;
use Vigilance\Models\FailureGroup;

uses(RefreshDatabase::class);

/** A root \Error re-wrapped $levels times into nested ViewExceptions (Blade + Livewire). */
function viewWrapped(string $rootMessage, int $levels = 3): Throwable
{
    $e = new Error($rootMessage);

    for ($i = 0; $i < $levels; $i++) {
        $e = new ViewException($rootMessage.str_repeat(' (View: /app/index.blade.php)', $i + 1), 0, 1, __FILE__, __LINE__, $e);
    }

    return $e;
}

it('captures a reported exception into a grouped issue with a sample', function () {
    $capture = app(IssueCapture::class);

    $capture->capture(new RuntimeException('boom'), 'reported');
    $capture->capture(new RuntimeException('boom'), 'reported');

    $group = FailureGroup::query()->first();

    expect($group)->not->toBeNull()
        ->and($group->occurrences)->toBe(2)
        ->and($group->source)->toBe('reported')
        ->and($group->exception_class)->toBe(RuntimeException::class)
        ->and($group->sample)->toContain('boom')
        ->and($group->context['file'] ?? null)->toContain(basename(__FILE__));
});

it('captures a wrapped exception by its root cause, not the envelope', function () {
    $capture = app(IssueCapture::class);

    $capture->capture(viewWrapped('Call to a member function newQueryWithoutRelationships() on null'), 'reported');

    $group = FailureGroup::query()->first();

    // Named and fingerprinted by the root \Error, not the outer ViewException.
    expect($group->exception_class)->toBe(Error::class)
        ->and($group->message)->toBe('Call to a member function newQueryWithoutRelationships() on null')
        // The de-noised message carries no "(View: …)" suffix.
        ->and($group->message)->not->toContain('(View:')
        // The sample leads with the root cause and pins a culprit line.
        ->and($group->sample)->toContain('[root cause] Error:')
        ->and($group->sample)->toContain('culprit:')
        ->and($group->context['culprit'] ?? null)->not->toBeNull();
});

it('groups the same root cause across differing wrapping depths', function () {
    $capture = app(IssueCapture::class);

    // Same bug, but Blade/Livewire wrapped it to different depths on two hits —
    // the "(View: …)" suffix repeats a different number of times each time.
    $capture->capture(viewWrapped('boom on null', levels: 3), 'reported');
    $capture->capture(viewWrapped('boom on null', levels: 2), 'reported');

    expect(FailureGroup::query()->count())->toBe(1)
        ->and((int) FailureGroup::query()->first()->occurrences)->toBe(2);
});

it('keeps two different root causes apart even under the same wrapper', function () {
    $capture = app(IssueCapture::class);

    // Two unrelated bugs in two views. Before root-unwrapping these could collide
    // under one "ViewException" fingerprint; now the roots keep them distinct.
    $capture->capture(viewWrapped('Call to newQueryWithoutRelationships() on null'), 'reported');
    $capture->capture(viewWrapped('Undefined property $foo'), 'reported');

    expect(FailureGroup::query()->count())->toBe(2);
});

it('reopens a resolved issue when it recurs', function () {
    $capture = app(IssueCapture::class);

    $capture->capture(new RuntimeException('x'), 'reported');
    $group = FailureGroup::query()->first();
    $group->update(['resolved_at' => now()]);

    $capture->capture(new RuntimeException('x'), 'reported');

    expect($group->fresh()->resolved_at)->toBeNull();
});

it('respects the issues.except ignore list', function () {
    config()->set('vigilance.issues.except', [RuntimeException::class]);

    app(IssueCapture::class)->capture(new RuntimeException('nope'), 'reported');

    expect(FailureGroup::query()->count())->toBe(0);
});

it('does nothing when issue capture is disabled', function () {
    config()->set('vigilance.issues.enabled', false);

    app(IssueCapture::class)->capture(new RuntimeException('off'), 'reported');

    expect(FailureGroup::query()->count())->toBe(0);
});

it('reports muted status from the model', function () {
    $group = new FailureGroup;
    $group->signature = 'sig-muted';
    $group->muted_until = now()->addHour();

    expect($group->isMuted())->toBeTrue()
        ->and($group->status())->toBe('muted');

    $group->muted_until = now()->subHour();

    expect($group->isMuted())->toBeFalse()
        ->and($group->status())->toBe('open');
});
