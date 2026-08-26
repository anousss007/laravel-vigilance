<?php

namespace Vigilance\Control;

use Illuminate\Database\Eloquent\Builder;
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

        if ($run->retries()->exists()) {
            throw new CannotRetry("Run [{$runId}] has already been retried.");
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
        $runs = $this->eligibleFailedJobs()
            ->where('failure_group_id', $groupId)
            ->get();

        $result = $this->retryMany($runs, $user);

        if ($result['retried'] > 0) {
            $this->resolveDrainedGroups([$groupId]);
        }

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
        $runs = $this->eligibleFailedJobs()
            ->where(function ($query) {
                $query->whereNull('failure_group_id')
                    ->orWhereHas('failureGroup', fn ($groups) => $groups
                        ->whereNull('resolved_at')
                        ->whereNull('merged_into'));
            })
            ->orderBy('id')
            ->limit(max(0, $cap))
            ->get();

        $groupIds = $runs->pluck('failure_group_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $result = $this->retryMany($runs, $user);

        $this->resolveDrainedGroups($groupIds);

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
     * Failed job runs that have not already produced a retry child.
     *
     * @return Builder<Run>
     */
    protected function eligibleFailedJobs(): Builder
    {
        return Run::query()
            ->failed()
            ->ofType(RunType::Job)
            ->whereDoesntHave('retries');
    }

    /**
     * Resolve only groups for which every retryable failed-job leaf was
     * dispatched. Skipped runs and runs left behind by the bulk cap keep their
     * issue open; unrelated non-job issues are never touched.
     *
     * @param  list<int>  $groupIds
     */
    protected function resolveDrainedGroups(array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }

        FailureGroup::query()
            ->whereIn('id', $groupIds)
            ->whereNull('resolved_at')
            ->whereDoesntHave('runs', fn ($runs) => $runs
                ->where('status', RunStatus::Failed->value)
                ->where('type', RunType::Job->value)
                ->whereDoesntHave('retries'))
            ->update(['resolved_at' => now()]);
    }

    /**
     * Reconstruct and re-dispatch a single failed job run.
     */
    protected function retryRun(Run $run, ?string $user): void
    {
        $job = $this->restore($run);

        // Re-dispatch inside a manual context carrying the parent run id, which
        // the capture layer reads at createPayloadUsing time to set the fresh
        // run's retry_of — no dynamic property on the job (deprecated on 8.2+).
        Vigilance::asManual($user, function () use ($job, $run) {
            $pending = dispatch($job);

            if ($run->queue) {
                $pending->onQueue($run->queue);
            }

            if ($run->connection_name) {
                $pending->onConnection($run->connection_name);
            }
        }, retryOf: $run->id);
    }

    /**
     * Reconstruct the original job instance from the run's stored payload using
     * an unserialize restricted to the original class only.
     *
     * Public so RunReplayer can reuse it: the restricted unserialize is the
     * security-sensitive part of this class, and a second copy would be a
     * second place to get it wrong.
     */
    public function restore(Run $run): object
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
