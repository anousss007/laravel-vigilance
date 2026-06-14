<?php

namespace Vigilance\Control;

use Illuminate\Support\Collection;
use Vigilance\Control\Exceptions\CannotRetry;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;
use Vigilance\Vigilance;

/**
 * Re-dispatches a previously failed job by faithfully reconstructing it from
 * the serialized command stored on the original run. Reconstruction uses a
 * restricted unserialize limited to the original job class, so a tampered
 * payload cannot instantiate arbitrary objects.
 */
class JobRetrier
{
    public function __construct(
        protected AuditLogger $audit = new AuditLogger,
    ) {}

    public function retry(int $runId, ?string $user = null): void
    {
        $run = Run::query()->find($runId);

        if ($run === null) {
            throw new CannotRetry("Run [{$runId}] not found.");
        }

        if ($run->status !== RunStatus::Failed) {
            throw new CannotRetry("Run [{$runId}] is not in a failed state and cannot be retried.");
        }

        if ($run->type !== RunType::Job) {
            throw new CannotRetry("Run [{$runId}] is not a job and cannot be retried.");
        }

        $this->retryRun($run, $user);

        $this->audit->log(
            action: 'retry',
            subject: $run->name,
            runId: $runId,
            meta: ['retry_of' => $run->id, 'connection' => $run->connection_name, 'queue' => $run->queue],
            user: $user,
        );
    }

    /**
     * Retry every failed job in a failure group, then mark the group resolved.
     *
     * @return array{retried: int, skipped: int}
     */
    public function retryGroup(int $groupId, ?string $user = null): array
    {
        $result = $this->retryMany(
            Run::query()->failed()->ofType(RunType::Job)->where('failure_group_id', $groupId)->get(),
            $user,
        );

        FailureGroup::query()->whereKey($groupId)->update(['resolved_at' => now()]);

        $this->audit->log(action: 'retry_group', subject: (string) $groupId, meta: $result, user: $user);

        return $result;
    }

    /**
     * Retry every failed job across all groups (up to $cap), then resolve the
     * open failure groups so they aren't retried twice.
     *
     * @return array{retried: int, skipped: int}
     */
    public function retryFailed(?string $user = null, int $cap = 1000): array
    {
        $result = $this->retryMany(
            Run::query()->failed()->ofType(RunType::Job)->limit($cap)->get(),
            $user,
        );

        FailureGroup::query()->whereNull('resolved_at')->update(['resolved_at' => now()]);

        $this->audit->log(action: 'retry_all', meta: $result, user: $user);

        return $result;
    }

    /**
     * @param  Collection<int, Run>  $runs
     * @return array{retried: int, skipped: int}
     */
    protected function retryMany(Collection $runs, ?string $user): array
    {
        $retried = 0;
        $skipped = 0;

        foreach ($runs as $run) {
            try {
                $this->retryRun($run, $user);
                $retried++;
            } catch (CannotRetry) {
                $skipped++;
            }
        }

        return ['retried' => $retried, 'skipped' => $skipped];
    }

    /**
     * Reconstruct and re-dispatch a single failed job run.
     */
    protected function retryRun(Run $run, ?string $user): void
    {
        $job = $this->restore($run);

        // Tag the lineage. The capture layer does not yet read this property,
        // so we also record the linkage in the audit trail.
        try {
            $job->vigilanceRetryOf = $run->id;
        } catch (\Throwable) {
            // Some jobs may forbid dynamic properties; lineage stays in audit.
        }

        Vigilance::asManual($user, function () use ($job, $run) {
            $pending = dispatch($job);

            if ($run->queue) {
                $pending->onQueue($run->queue);
            }

            if ($run->connection_name) {
                $pending->onConnection($run->connection_name);
            }
        });
    }

    /**
     * Reconstruct the original job instance from the run's stored payload using
     * an unserialize restricted to the original class only.
     */
    protected function restore(Run $run): object
    {
        $serialized = $run->payload_raw;

        if (! is_string($serialized) || $serialized === '') {
            throw new CannotRetry(
                "Run [{$run->id}] has no stored payload to retry from. ".
                'Enable vigilance.capture.store_for_retry to retry jobs.',
            );
        }

        $class = $run->name;

        if (! is_string($class) || ! class_exists($class)) {
            throw new CannotRetry("Run [{$run->id}] references an unknown job class [{$class}].");
        }

        try {
            $job = @unserialize($serialized, ['allowed_classes' => [$class]]);
        } catch (\Throwable $e) {
            throw new CannotRetry("Run [{$run->id}] payload could not be unserialized: {$e->getMessage()}");
        }

        if (! is_object($job) || ! $job instanceof $class) {
            throw new CannotRetry(
                "Run [{$run->id}] payload did not restore to a [{$class}] instance.",
            );
        }

        return $job;
    }
}
