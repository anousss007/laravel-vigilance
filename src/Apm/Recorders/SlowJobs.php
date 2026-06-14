<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\Sampling;
use Vigilance\Apm\Recorders\Concerns\Thresholds;

/**
 * Records jobs slower than the threshold, keyed by job name (max + count). A
 * worker processes one job at a time, so a single "started at" marker bridges
 * JobProcessing → Processed/Failed/Released.
 */
class SlowJobs extends Recorder
{
    use Ignores;
    use Sampling;
    use Thresholds;

    protected ?float $startedAtMs = null;

    /** @var list<class-string> */
    public array $listen = [
        JobProcessing::class,
        JobProcessed::class,
        JobReleasedAfterException::class,
        JobFailed::class,
    ];

    public function record(JobProcessing|JobProcessed|JobReleasedAfterException|JobFailed $event): void
    {
        if ($event->connectionName === 'sync') {
            return;
        }

        if ($event instanceof JobProcessing) {
            $this->startedAtMs = microtime(true) * 1000;

            return;
        }

        if ($this->startedAtMs === null) {
            return;
        }

        $now = time();
        $duration = (int) round((microtime(true) * 1000) - $this->startedAtMs);
        $name = $event->job->resolveName();
        $this->startedAtMs = null;

        $this->apm->lazy(function () use ($now, $duration, $name) {
            if (! $this->shouldSample() || $this->shouldIgnore($name) || $this->underThreshold($duration, $name)) {
                return;
            }

            $this->apm->record('slow_job', $name, $duration, $now)->max()->count();
        });
    }
}
