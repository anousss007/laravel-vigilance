<?php

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Entry;
use Vigilance\Apm\Ingests\RedisIngest;
use Vigilance\Apm\Value;

uses(RefreshDatabase::class);

it('round-trips an Entry and a Value through encode/decode', function () {
    $entry = (new Entry(1_700_000_000, 'slow_query', 'select 1', 42))->max()->count();
    $value = new Value(1_700_000_000, 'system', 'web-1', '{"cpu":10}');

    $decodedEntry = RedisIngest::decode(RedisIngest::encode($entry));
    $decodedValue = RedisIngest::decode(RedisIngest::encode($value));

    expect($decodedEntry)->toBeInstanceOf(Entry::class)
        ->and($decodedEntry->type)->toBe('slow_query')
        ->and($decodedEntry->value)->toBe(42)
        ->and($decodedEntry->aggregations)->toBe(['max', 'count'])
        ->and($decodedValue)->toBeInstanceOf(Value::class)
        ->and($decodedValue->value)->toBe('{"cpu":10}');
});

it('rejects decoding arbitrary classes', function () {
    expect(RedisIngest::decode(base64_encode(serialize(new stdClass))))->toBeNull()
        ->and(RedisIngest::decode(''))->toBeNull();
});

it('pushes buffered items onto the stream (XADD with MAXLEN)', function () {
    $calls = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('command')->andReturnUsing(function ($cmd) use (&$calls) {
        $calls[] = $cmd;

        return null;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    (new RedisIngest(app(Storage::class)))->ingest(collect([
        (new Entry(time(), 'redis_test', 'k', 1))->count(),
        new Value(time(), 'sys', 'web', 'x'),
    ]));

    expect($calls)->toBe(['xadd', 'xadd']);
});

it('digests stream entries into storage', function () {
    $entry = (new Entry(time(), 'redis_test', 'k', 5))->count();

    $conn = Mockery::mock();
    $conn->shouldReceive('command')->andReturnUsing(fn ($cmd) => $cmd === 'xrange'
        ? ['1-0' => ['data' => RedisIngest::encode($entry)]]
        : null);
    Redis::shouldReceive('connection')->andReturn($conn);

    $processed = (new RedisIngest(app(Storage::class)))->digest(app(Storage::class));

    expect($processed)->toBe(1)
        ->and(app(Storage::class)->aggregateTotal('redis_test', 'count', CarbonInterval::hours(1)))->toBe(1.0);
});

it('resolves the redis ingest driver', function () {
    config()->set('vigilance.apm.ingest.driver', 'redis');
    config()->set('vigilance.apm.ingest.exporters', []);
    app()->forgetInstance(Ingest::class);

    expect(app(Ingest::class))->toBeInstanceOf(RedisIngest::class);
});
