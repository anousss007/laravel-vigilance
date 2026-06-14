<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\Sampling;

/**
 * Records queue throughput as time-bucketed counters per connection:queue —
 * queued / processing / processed / released / failed — powering the job
 * throughput card. Driver-agnostic (any non-sync connection).
 */
class Queues extends Recorder
{
    use Ignores;
    use Sampling;

    /** @var list<class-string> */
    public array $listen = [
        JobQueued::class,
        JobProcessing::class,
        JobProcessed::class,
        JobReleasedAfterException::class,
        JobFailed::class,
    ];

    public function record(JobQueued|JobProcessing|JobProcessed|JobReleasedAfterException|JobFailed $event): void
    {
        if ($event->connectionName === 'sync') {
            return;
        }

        $now = time();
        $type = $this->stateType($event);
        $connection = (string) ($event->connectionName ?: 'default');
        $queue = $this->resolveQueue($event) ?: $this->defaultQueue($connection);
        $name = $this->jobName($event);
        $uuid = $this->uuid($event);

        $this->apm->lazy(function () use ($type, $connection, $queue, $name, $uuid, $now) {
            $sampled = $uuid !== null ? $this->shouldSampleDeterministically($uuid) : $this->shouldSample();

            if (! $sampled || $this->shouldIgnore($name)) {
                return;
            }

            $this->apm->record($type, $connection.':'.$queue, null, $now)->count()->onlyBuckets();
        });
    }

    protected function stateType(object $event): string
    {
        return match (true) {
            $event instanceof JobQueued => 'queue_queued',
            $event instanceof JobProcessing => 'queue_processing',
            $event instanceof JobProcessed => 'queue_processed',
            $event instanceof JobReleasedAfterException => 'queue_released',
            default => 'queue_failed',
        };
    }

    protected function jobName(object $event): string
    {
        if ($event instanceof JobQueued) {
            return $this->nameOfQueuedJob($event->job);
        }

        return method_exists($event->job, 'resolveName') ? $event->job->resolveName() : $event->job::class;
    }

    protected function nameOfQueuedJob(mixed $job): string
    {
        if (is_string($job)) {
            return $job;
        }

        if (! is_object($job)) {
            return 'job';
        }

        return method_exists($job, 'displayName') ? $job->displayName() : $job::class;
    }

    protected function resolveQueue(object $event): ?string
    {
        if ($event instanceof JobQueued) {
            $job = $event->job;

            return is_object($job) ? ($job->queue ?? null) : null;
        }

        return method_exists($event->job, 'getQueue') ? $event->job->getQueue() : null;
    }

    protected function uuid(object $event): ?string
    {
        if ($event instanceof JobQueued) {
            return $event->payload()['uuid'] ?? null;
        }

        return method_exists($event->job, 'uuid') ? $event->job->uuid() : null;
    }

    protected function defaultQueue(string $connection): string
    {
        return (string) $this->config->get('queue.connections.'.$connection.'.queue', 'default');
    }
}
