<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Vigilance\Metrics\SelfUsage;

uses(RefreshDatabase::class);

/**
 * The Usage page dates each table by a column that is either unix seconds or a
 * datetime, and comparing the wrong kind is a bug SQLite cannot show you: it is
 * loosely typed and accepts `integer >= '2026-08-14 10:00:00'` happily.
 * PostgreSQL rejects it — and then aborts the surrounding transaction, so every
 * later query on the page silently returns null too.
 *
 * This asserts the map against the real schema, so CI's postgres/mysql run is
 * what catches the next one rather than a user's dashboard.
 */
it('declares the right storage kind for every column it dates a table by', function () {
    $connection = config('vigilance.storage.connection') ?: config('database.default');

    foreach (SelfUsage::timeColumns() as $table => $meta) {
        expect(Schema::connection($connection)->hasTable($table))
            ->toBeTrue("{$table} is in the map but not in the schema");

        expect(Schema::connection($connection)->hasColumn($table, $meta['column']))
            ->toBeTrue("{$table}.{$meta['column']} is in the map but not in the schema");

        $type = Schema::connection($connection)->getColumnType($table, $meta['column']);

        $isIntegerish = (bool) preg_match('/int/i', $type);

        expect($isIntegerish)->toBe(
            $meta['unix'],
            "{$table}.{$meta['column']} is a [{$type}] but the map says unix=".var_export($meta['unix'], true),
        );
    }
});

it('reads every dated table without poisoning the connection', function () {
    // The cascade this guards: one bad comparison on PostgreSQL aborts the
    // transaction, and every table after it in the loop reads back as null.
    $rows = collect(app(SelfUsage::class)->tables())->keyBy('table');

    foreach (SelfUsage::timeColumns() as $table => $meta) {
        expect($rows[$table]['rows'])->not->toBeNull("{$table} failed to count")
            ->and($rows[$table]['last_day'])->not->toBeNull("{$table} failed its since-query");
    }
});
