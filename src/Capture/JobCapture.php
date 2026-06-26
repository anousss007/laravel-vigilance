<?php

namespace Vigilance\Capture;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Queue;

class JobCapture
{
    /** Prevents Queue::createPayloadUsing() accumulating in the static callback array when the app boots twice (e.g. Vapor's Octane runtime). */
    private static bool $payloadCallbackRegistered = false;

    public function __construct(protected Recorder $recorder) {}

    public function register(): void
    {
        if (! static::$payloadCallbackRegistered) {
            static::$payloadCallbackRegistered = true;

            // The correlation trick: a uuid lives inside the payload, so a job can
            // be tracked from "queued" through "processed/failed" across ANY queue
            // driver (sync, database, redis, sqs, beanstalkd).
            Queue::createPayloadUsing(function ($connection, $queue, $payload) {
                return $this->recorder->onJobPayloadCreate((string) $connection, $queue, $payload);
            });
        }

        $events = app('events');

        $events->listen(JobProcessing::class, fn (JobProcessing $e) => $this->recorder->jobProcessing($e));
        $events->listen(JobProcessed::class, fn (JobProcessed $e) => $this->recorder->jobProcessed($e));
        $events->listen(JobFailed::class, fn (JobFailed $e) => $this->recorder->jobFailed($e));
        $events->listen(JobReleasedAfterException::class, fn (JobReleasedAfterException $e) => $this->recorder->jobReleased($e));
    }
}
