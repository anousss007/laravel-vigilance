<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Vigilance\Capture\FailureGrouper;
use Vigilance\Models\FailureGroup;
use Vigilance\Notifications\Rules\IssueRegressionRule;
use Vigilance\Notifications\Rules\NewIssueRule;

uses(RefreshDatabase::class);

function recordFailure(string $message = 'boom'): int
{
    return app(FailureGrouper::class)->record(
        type: 'job',
        name: 'App\\Jobs\\Thing',
        exceptionClass: 'RuntimeException',
        message: $message,
    );
}

it('stamps regressed_at when a resolved issue recurs', function () {
    $id = recordFailure();

    FailureGroup::query()->whereKey($id)->update(['resolved_at' => now()]);

    recordFailure(); // same signature → recurs

    $group = FailureGroup::query()->findOrFail($id);

    expect($group->resolved_at)->toBeNull()
        ->and($group->regressed_at)->not->toBeNull()
        ->and($group->isRegressed())->toBeTrue();
});

it('alerts on a brand-new issue within the window', function () {
    recordFailure();

    $alerts = collect(app(NewIssueRule::class)->evaluate());

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->key)->toStartWith('issue_new:')
        ->and($alerts->first()->level)->toBe('warning');
});

it('does not alert on issues older than the window, or resolved ones', function () {
    $old = recordFailure('old one');
    FailureGroup::query()->whereKey($old)->update(['first_seen_at' => Carbon::now()->subHour()]);

    $resolved = app(FailureGrouper::class)->record('job', 'App\\Jobs\\Other', 'LogicException', 'fresh but resolved');
    FailureGroup::query()->whereKey($resolved)->update(['resolved_at' => now()]);

    expect(collect(app(NewIssueRule::class)->evaluate()))->toHaveCount(0);
});

it('alerts on a regressed issue as critical', function () {
    $id = recordFailure();
    FailureGroup::query()->whereKey($id)->update(['resolved_at' => now()]);
    recordFailure(); // regression

    $alerts = collect(app(IssueRegressionRule::class)->evaluate());

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->key)->toBe('issue_regressed:'.$id)
        ->and($alerts->first()->level)->toBe('critical');

    // A never-resolved issue is not a regression.
    app(FailureGrouper::class)->record('job', 'App\\Jobs\\NeverResolved', 'Exception', 'x');
    expect(collect(app(IssueRegressionRule::class)->evaluate()))->toHaveCount(1);
});
