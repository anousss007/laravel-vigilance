<?php

use Vigilance\Capture\Recorder;

/**
 * The Redis queue driver reports the queue as its storage key ("queues:default")
 * while the database/beanstalkd drivers (and the supervisor, queue-depth probe
 * and config) use the logical name ("default"). The recorder normalizes redis
 * names so per-queue grouping is consistent across drivers.
 */
function normalizeQueue(?string $connection, ?string $queue): mixed
{
    $recorder = app(Recorder::class);
    $method = new ReflectionMethod($recorder, 'normalizeQueue');
    $method->setAccessible(true);

    return $method->invoke($recorder, $connection, $queue);
}

beforeEach(function () {
    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.database', ['driver' => 'database']);
    config()->set('queue.connections.beanstalkd', ['driver' => 'beanstalkd']);
});

it('strips the redis storage prefix to the logical queue name', function () {
    expect(normalizeQueue('redis', 'queues:default'))->toBe('default')
        ->and(normalizeQueue('redis', 'queues:emails'))->toBe('emails');
});

it('leaves non-redis driver queue names untouched', function () {
    expect(normalizeQueue('database', 'default'))->toBe('default')
        ->and(normalizeQueue('beanstalkd', 'default'))->toBe('default')
        ->and(normalizeQueue('database', 'queues:weird'))->toBe('queues:weird');
});

it('leaves a redis queue without the prefix untouched', function () {
    expect(normalizeQueue('redis', 'default'))->toBe('default');
});

it('handles null / empty / unknown connection gracefully', function () {
    expect(normalizeQueue('redis', null))->toBeNull()
        ->and(normalizeQueue('redis', ''))->toBe('')
        ->and(normalizeQueue(null, 'default'))->toBe('default')
        ->and(normalizeQueue('does-not-exist', 'default'))->toBe('default');
});
