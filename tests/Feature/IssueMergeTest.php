<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Vigilance\Capture\FailureGrouper;
use Vigilance\Control\IssueMerger;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;

uses(RefreshDatabase::class);

function seedFailureRun(int $groupId): void
{
    Run::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => RunType::Job->value,
        'name' => 'App\\Jobs\\Demo',
        'status' => RunStatus::Failed->value,
        'failure_group_id' => $groupId,
    ]);
}

it('merges a source issue into a target, moving runs and occurrences', function () {
    $grouper = app(FailureGrouper::class);
    $a = $grouper->record('job', 'A', 'RuntimeException', 'boom A');
    $b = $grouper->record('job', 'B', 'RuntimeException', 'boom B');
    seedFailureRun($b);

    $result = app(IssueMerger::class)->merge($b, $a, 'me@test');

    $groupA = FailureGroup::find($a);
    $groupB = FailureGroup::find($b);

    expect($result['merged_into'])->toBe($a)
        ->and($groupB->merged_into)->toBe($a)
        ->and($groupB->resolved_at)->not->toBeNull()
        ->and(Run::query()->where('failure_group_id', $b)->count())->toBe(0)
        ->and(Run::query()->where('failure_group_id', $a)->count())->toBe(1)
        ->and($groupA->occurrences)->toBe(2); // 1 (A) + 1 (B)

    $this->assertDatabaseHas('vigilance_audit', ['action' => 'merge_issue', 'user' => 'me@test']);
});

it('redirects future occurrences of a merged signature to the target', function () {
    $grouper = app(FailureGrouper::class);
    $a = $grouper->record('job', 'A', 'RuntimeException', 'boom A');
    $b = $grouper->record('job', 'B', 'RuntimeException', 'boom B');

    app(IssueMerger::class)->merge($b, $a);

    // The same error that used to hit B now lands on A.
    $grouper->record('job', 'B', 'RuntimeException', 'boom B');

    expect(FailureGroup::find($a)->occurrences)->toBe(3)  // 1 + 1 (merged) + 1 (redirected)
        ->and(FailureGroup::find($b)->occurrences)->toBe(1); // unchanged
});

it('refuses to merge an issue into itself', function () {
    $grouper = app(FailureGrouper::class);
    $a = $grouper->record('job', 'A', 'RuntimeException', 'boom A');

    expect(fn () => app(IssueMerger::class)->merge($a, $a))
        ->toThrow(InvalidArgumentException::class);
});
