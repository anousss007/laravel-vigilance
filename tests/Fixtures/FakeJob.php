<?php

namespace Vigilance\Tests\Fixtures;

/**
 * A minimal stand-in for a queue Job, carrying just the bits the APM job
 * recorders read. The queue event constructors don't type-hint $job, so this is
 * enough to drive Queues / SlowJobs / UserJobs in tests.
 */
class FakeJob
{
    public ?string $queue = 'default';

    public function __construct(
        public string $name = 'App\\Jobs\\Demo',
        public string $uuid = 'uuid-1',
    ) {}

    public function resolveName(): string
    {
        return $this->name;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function getQueue(): string
    {
        return $this->queue ?? 'default';
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function getConnectionName(): string
    {
        return 'database';
    }
}
