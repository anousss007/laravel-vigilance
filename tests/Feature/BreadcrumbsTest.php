<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Vigilance\Capture\IssueCapture;
use Vigilance\Models\FailureGroup;
use Vigilance\Support\Breadcrumbs;
use Vigilance\Vigilance;

uses(RefreshDatabase::class);

beforeEach(fn () => app(Breadcrumbs::class)->clear());

it('rings the buffer at the configured max', function () {
    $crumbs = new Breadcrumbs(max: 3);

    foreach (['a', 'b', 'c', 'd', 'e'] as $m) {
        $crumbs->add($m);
    }

    expect($crumbs->all())->toHaveCount(3)
        ->and(array_column($crumbs->all(), 'message'))->toBe(['c', 'd', 'e']);
});

it('records breadcrumbs through the Vigilance API and redacts data', function () {
    Vigilance::breadcrumb('Charged card', 'billing', data: ['amount' => 20, 'token' => 'secret-abc']);

    $all = app(Breadcrumbs::class)->all();

    expect($all)->toHaveCount(1)
        ->and($all[0]['message'])->toBe('Charged card')
        ->and($all[0]['category'])->toBe('billing')
        ->and($all[0]['data']['amount'])->toBe(20)
        ->and($all[0]['data']['token'])->not->toBe('secret-abc'); // redacted
});

it('auto-records log lines as breadcrumbs', function () {
    Log::info('User signed in');
    Log::warning('Rate limit near');

    $messages = array_column(app(Breadcrumbs::class)->all(), 'message');

    expect($messages)->toContain('User signed in')
        ->and($messages)->toContain('Rate limit near')
        ->and(collect(app(Breadcrumbs::class)->all())->firstWhere('message', 'User signed in')['category'])->toBe('log');
});

it('attaches the trail to an issue when an exception is captured', function () {
    Vigilance::breadcrumb('Loaded order #42', 'order');
    Log::error('Payment gateway timeout');

    app(IssueCapture::class)->capture(new RuntimeException('boom'), 'reported');

    $group = FailureGroup::query()->where('exception_class', RuntimeException::class)->first();

    expect($group)->not->toBeNull()
        ->and($group->context)->toHaveKey('breadcrumbs');

    $messages = array_column($group->context['breadcrumbs'], 'message');
    expect($messages)->toContain('Loaded order #42')
        ->and($messages)->toContain('Payment gateway timeout');
});

it('clears the trail on the request/worker boundary', function () {
    Vigilance::breadcrumb('before');
    expect(app(Breadcrumbs::class)->all())->toHaveCount(1);

    Vigilance::flushState();

    expect(app(Breadcrumbs::class)->all())->toBe([]);
});
