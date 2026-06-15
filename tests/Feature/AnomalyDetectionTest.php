<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Vigilance\Notifications\Rules\AnomalyRule;

uses(RefreshDatabase::class);

/**
 * Seed the 6h-window aggregate buckets (period 360 → 6-min buckets) for one
 * route's average latency: a flat baseline plus an optional final spike.
 */
function seedLatencySeries(string $path, int $baselineMs, ?int $spikeMs): void
{
    $key = (string) json_encode(['GET', $path]);
    $period = 360;
    $now = now()->getTimestamp();
    $current = (int) (floor($now / 360) * 360);

    $rows = [];
    for ($i = 30; $i >= 1; $i--) {
        $rows[] = [
            'bucket' => $current - $i * 360,
            'period' => $period,
            'type' => 'request',
            'key' => $key,
            'key_hash' => md5($key),
            'aggregate' => 'avg',
            'value' => $baselineMs + ($i % 4),
            'count' => 10,
        ];
    }

    if ($spikeMs !== null) {
        $rows[] = [
            'bucket' => $current,
            'period' => $period,
            'type' => 'request',
            'key' => $key,
            'key_hash' => md5($key),
            'aggregate' => 'avg',
            'value' => $spikeMs,
            'count' => 10,
        ];
    }

    // key_hash is a generated column on MySQL/Postgres (only a plain column on SQLite).
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $rows = array_map(function ($r) {
            unset($r['key_hash']);

            return $r;
        }, $rows);
    }

    DB::table('vigilance_aggregates')->insert($rows);
}

it('fires when a route latency spikes far above its baseline', function () {
    seedLatencySeries('/checkout', baselineMs: 100, spikeMs: 3000);

    $alerts = collect(app(AnomalyRule::class)->evaluate());

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->key)->toStartWith('anomaly:request:')
        ->and($alerts->first()->message)->toContain('GET /checkout')
        ->and($alerts->first()->title)->toContain('latency');
});

it('stays quiet on a flat series', function () {
    seedLatencySeries('/stable', baselineMs: 120, spikeMs: null);

    expect(collect(app(AnomalyRule::class)->evaluate()))->toHaveCount(0);
});

it('ignores a tiny absolute jump below the floor', function () {
    // 2ms → 40ms is a huge z-score but trivial in absolute terms (< 150ms floor).
    seedLatencySeries('/cheap', baselineMs: 2, spikeMs: 40);

    expect(collect(app(AnomalyRule::class)->evaluate()))->toHaveCount(0);
});
