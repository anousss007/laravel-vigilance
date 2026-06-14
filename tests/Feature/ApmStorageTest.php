<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Vigilance\Apm\Entry;
use Vigilance\Apm\Storage\DatabaseStorage;
use Vigilance\Apm\Value;

uses(RefreshDatabase::class);

function apmStorage(): DatabaseStorage
{
    return new DatabaseStorage;
}

it('stores entries and aggregates max + count over the window', function () {
    $now = time();

    $items = collect([100, 300, 200])->map(fn ($v) => (new Entry(
        timestamp: $now,
        type: 'test_query',
        key: 'SELECT 1',
        value: $v,
    ))->max()->count());

    apmStorage()->store($items);

    $rows = apmStorage()->aggregate('test_query', ['max', 'count'], CarbonInterval::hours(1));

    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->key)->toBe('SELECT 1')
        ->and((int) $row->max)->toBe(300)
        ->and((int) $row->count)->toBe(3);
});

it('returns a 60-point graph series summing to the total count', function () {
    $now = time();

    $items = collect([100, 300, 200])->map(fn ($v) => (new Entry(
        timestamp: $now,
        type: 'test_query',
        key: 'SELECT 1',
        value: $v,
    ))->count());

    apmStorage()->store($items);

    $graph = apmStorage()->graph(['test_query'], 'count', CarbonInterval::hours(1));

    $series = $graph['SELECT 1']['test_query'];

    expect($series)->toHaveCount(60)
        ->and(collect($series)->filter()->sum())->toBe(3);
});

it('computes a scalar aggregate total across the window', function () {
    $now = time();

    $items = collect([100, 300, 200])->map(fn ($v) => (new Entry(
        timestamp: $now,
        type: 'test_query',
        key: 'SELECT 1',
        value: $v,
    ))->count());

    apmStorage()->store($items);

    expect(apmStorage()->aggregateTotal('test_query', 'count', CarbonInterval::hours(1)))->toBe(3.0);
});

it('upserts values latest-wins by type and key', function () {
    $storage = apmStorage();

    $storage->store(collect([
        new Value(timestamp: time() - 10, type: 'system', key: 'cpu', value: '10'),
    ]));

    $storage->store(collect([
        new Value(timestamp: time(), type: 'system', key: 'cpu', value: '42'),
    ]));

    $values = $storage->values('system');

    expect($values)->toHaveCount(1)
        ->and($values['cpu']->value)->toBe('42');
});

it('skips raw entries for onlyBuckets but still aggregates', function () {
    $now = time();

    $items = collect([100, 300, 200])->map(fn ($v) => (new Entry(
        timestamp: $now,
        type: 'bucketed',
        key: 'SELECT 2',
        value: $v,
    ))->max()->count()->onlyBuckets());

    apmStorage()->store($items);

    // No raw rows landed in the entries table.
    expect(DB::table('vigilance_entries')->where('type', 'bucketed')->count())->toBe(0);

    // But the aggregate is still readable from the buckets.
    $rows = apmStorage()->aggregate('bucketed', ['max', 'count'], CarbonInterval::hours(1));

    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect((int) $row->max)->toBe(300)
        ->and((int) $row->count)->toBe(3);
});
