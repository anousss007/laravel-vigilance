<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Queue\Events\JobQueued;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\Sampling;

/**
 * Counts jobs dispatched per authenticated user (the "jobs" dimension of the
 * Application Usage card). Recorded at dispatch time, so it attributes the job
 * to whoever queued it.
 */
class UserJobs extends Recorder
{
    use Ignores;
    use Sampling;

    /** @var list<class-string> */
    public array $listen = [JobQueued::class];

    public function record(JobQueued $event): void
    {
        if ($event->connectionName === 'sync') {
            return;
        }

        $now = time();
        $name = $this->jobName($event);
        $user = $this->apm->resolveUser();

        if ($user === null) {
            return;
        }

        $this->apm->lazy(function () use ($now, $name, $user) {
            if (! $this->shouldSample() || $this->shouldIgnore($name)) {
                return;
            }

            $this->apm->record('user_job', (string) $user['id'], null, $now)->count();
            $this->apm->set('user', (string) $user['id'], (string) json_encode($user), $now);
        });
    }

    protected function jobName(JobQueued $event): string
    {
        $job = $event->job;

        if (is_string($job)) {
            return $job;
        }

        return method_exists($job, 'displayName') ? $job->displayName() : $job::class;
    }
}
