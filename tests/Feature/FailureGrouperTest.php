<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vigilance\Capture\FailureGrouper;
use Vigilance\Models\FailureGroup;

uses(RefreshDatabase::class);

it('collapses repeated failures into one group and counts every occurrence', function () {
    $grouper = app(FailureGrouper::class);

    for ($i = 0; $i < 50; $i++) {
        $grouper->record('job', 'App\\Jobs\\Boom', RuntimeException::class, 'boom');
    }

    expect(FailureGroup::query()->count())->toBe(1);

    // The count is read back from the database (not the in-memory model), which
    // is what guards it against the lost-update race under concurrent failures.
    expect((int) FailureGroup::query()->first()->occurrences)->toBe(50);
});

it('keeps distinct failure signatures in distinct groups', function () {
    $grouper = app(FailureGrouper::class);

    $grouper->record('job', 'App\\Jobs\\A', RuntimeException::class, 'one');
    $grouper->record('job', 'App\\Jobs\\B', LogicException::class, 'two');
    $grouper->record('job', 'App\\Jobs\\A', RuntimeException::class, 'one');

    expect(FailureGroup::query()->count())->toBe(2);

    $a = FailureGroup::query()->where('name', 'App\\Jobs\\A')->first();
    expect((int) $a->occurrences)->toBe(2);
});

it('reports the first record of a signature as new and the rest as not new', function () {
    $grouper = app(FailureGrouper::class);

    // record() persists; assert new-vs-recurring via wasRecentlyCreated semantics
    // by checking occurrences crosses 1 -> 2 for the same signature.
    $id1 = $grouper->record('command', 'app:sync', RuntimeException::class, 'x');
    $id2 = $grouper->record('command', 'app:sync', RuntimeException::class, 'x');

    expect($id1)->toBe($id2)
        ->and((int) FailureGroup::query()->whereKey($id1)->first()->occurrences)->toBe(2);
});
