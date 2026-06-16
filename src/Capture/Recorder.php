<?php

namespace Vigilance\Capture;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Vigilance\Contracts\RunRepository;
use Vigilance\Data\RunData;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Support\Redactor;
use Vigilance\Vigilance;

class Recorder
{
    /** @var array<int, array{id: int|string, name: string, started: float, cpu: ?float}> */
    protected array $commandStack = [];

    /** @var array<string, ?float> CPU baseline (ms) per job uuid, keyed while processing. */
    protected array $cpuStart = [];

    public function __construct(
        protected RunRepository $runs,
        protected FailureGrouper $failures,
    ) {}

    /**
     * Clear the in-process command frame stack. Called on Octane request
     * boundaries so a half-finished command can't leak across requests.
     */
    public function flushStack(): void
    {
        $this->commandStack = [];
        $this->cpuStart = [];
    }

    // ---------------------------------------------------------------------
    // Jobs
    // ---------------------------------------------------------------------

    /**
     * Invoked from Queue::createPayloadUsing at dispatch time. Records the
     * "queued" run and injects a retention flag into the payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function onJobPayloadCreate(string $connection, ?string $queue, array $payload): array
    {
        return $this->guard(function () use ($connection, $queue, $payload) {
            if (! Vigilance::shouldRecord()) {
                return [];
            }

            // At createPayloadUsing time the framework has not yet serialized the
            // command: data.commandName / data.command are the raw job OBJECT.
            $commandName = $payload['data']['commandName'] ?? null;
            $class = is_object($commandName) ? get_class($commandName) : $commandName;

            if ($class && Vigilance::ignoresJob($class)) {
                return [];
            }

            $uuid = $payload['uuid'] ?? null;
            $injectUuid = false;

            if (! $uuid) {
                $uuid = (string) Str::uuid();
                $injectUuid = true;
            }

            $manual = Vigilance::manualContext();

            $data = RunData::make([
                'uuid' => $uuid,
                'name' => $class,
                'display_name' => $payload['displayName'] ?? $class,
                'connection_name' => $connection,
                'queue' => $this->normalizeQueue($connection, $queue),
                'attempt' => 1,
                'queued_at' => Carbon::now(),
                'via' => $manual ? 'manual' : 'auto',
                'caused_by' => $manual['user'] ?? null,
            ])->type(RunType::Job)->status(RunStatus::Queued);

            if (config('vigilance.capture.store_for_retry', true)) {
                $command = $payload['data']['command'] ?? null;
                $data->set('payload_raw', match (true) {
                    is_string($command) => $command,
                    is_object($command) => @serialize($command),
                    default => null,
                });
            }

            // Decide sampling at enqueue time. A sampled-out *successful* job is
            // never written — zero DB cost. Failures are captured later in
            // jobFailed() regardless of this flag, so nothing is silently lost.
            $keep = Vigilance::passesSampling();

            if ($keep) {
                $this->runs->insert($data);
            }

            return array_merge(
                ['vigilance_keep' => $keep ? 1 : 0],
                $injectUuid ? ['uuid' => $uuid] : [],
            );
        }) ?? [];
    }

    public function jobProcessing(JobProcessing $event): void
    {
        $this->guard(function () use ($event) {
            if (! Vigilance::shouldRecord()) {
                return;
            }

            $payload = $event->job->payload();
            $class = $payload['data']['commandName'] ?? null;

            if ($class && Vigilance::ignoresJob($class)) {
                return;
            }

            $uuid = $this->uuidFor($event->job, $payload);

            if (! $uuid) {
                return;
            }

            $this->cpuStart[$uuid] = $this->rusage();

            $now = Carbon::now();
            $changes = RunData::make([
                'started_at' => $now,
                'attempt' => max(1, (int) $event->job->attempts()),
            ])->status(RunStatus::Running);

            [$parameters, $tags] = $this->extract($payload);

            if ($parameters !== null) {
                $changes->set('parameters', $parameters);
            }

            if ($tags !== []) {
                $changes->set('tags', $tags);
            }

            // Link the run to its batch (Batchable jobs carry a batchId; it is
            // null for jobs not dispatched as part of a batch) so a batch can be
            // drilled into its individual job runs.
            $batchId = $payload['data']['batchId'] ?? null;
            if (is_string($batchId) && $batchId !== '') {
                $changes->set('batch_id', $batchId);
            }

            $open = $this->runs->findOpenByUuid($uuid);

            if ($open) {
                if ($open->queued_at) {
                    $changes->set('wait_ms', (int) round($open->queued_at->diffInMilliseconds($now)));
                }

                $this->runs->update($open->id, $changes);
                $this->runs->attachTags($open->id, $tags);

                return;
            }

            // Sampled-out successful job: no row was created at enqueue, so we
            // skip it here too. If it ends up failing, jobFailed() captures it.
            if ((int) ($payload['vigilance_keep'] ?? 1) === 0) {
                return;
            }

            // No queued row (e.g. dispatched before Vigilance booted). Insert
            // a fresh running row so the job is still tracked.
            $manual = Vigilance::manualContext();
            $changes->attributes = array_merge($changes->toArray(), [
                'uuid' => $uuid,
                'name' => $class,
                'display_name' => $payload['displayName'] ?? $class,
                'connection_name' => $event->connectionName,
                'queue' => $this->normalizeQueue($event->connectionName, $event->job->getQueue()),
                'via' => $manual ? 'manual' : 'auto',
                'caused_by' => $manual['user'] ?? null,
                'type' => RunType::Job->value,
            ]);

            $this->runs->insert($changes);
        });
    }

    public function jobProcessed(JobProcessed $event): void
    {
        $this->guard(function () use ($event) {
            if (! Vigilance::shouldRecord()) {
                return;
            }

            if ($event->job->isReleased()) {
                return; // handled by jobReleased
            }

            $payload = $event->job->payload();
            $uuid = $this->uuidFor($event->job, $payload);

            if (! $uuid || ! ($open = $this->runs->findOpenByUuid($uuid))) {
                return;
            }

            // Sampled-out successful run: drop it to keep tables bounded.
            if ((int) ($payload['vigilance_keep'] ?? 1) === 0) {
                $this->runs->delete($open->id);

                return;
            }

            $now = Carbon::now();

            $this->runs->update($open->id, RunData::make([
                'finished_at' => $now,
                'duration_ms' => $open->started_at !== null ? (int) round($open->started_at->diffInMilliseconds($now)) : null,
                'memory_peak' => $this->memory(),
                'cpu_time_ms' => $this->cpuDelta($uuid),
            ])->status(RunStatus::Succeeded));
        });
    }

    public function jobFailed(JobFailed $event): void
    {
        $this->guard(function () use ($event) {
            if (! Vigilance::shouldRecord()) {
                return;
            }

            $payload = $event->job->payload();
            $class = $payload['data']['commandName'] ?? null;

            if ($class && Vigilance::ignoresJob($class)) {
                return;
            }

            $uuid = $this->uuidFor($event->job, $payload);
            $now = Carbon::now();
            $exception = $event->exception;

            $groupId = $this->failures->record(
                RunType::Job->value,
                $class,
                get_class($exception),
                $exception->getMessage(),
            );

            $changes = RunData::make([
                'finished_at' => $now,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception' => $this->truncate((string) $exception, 'max_exception_length'),
                'failure_group_id' => $groupId,
                'memory_peak' => $this->memory(),
                'cpu_time_ms' => $uuid ? $this->cpuDelta($uuid) : null,
            ])->status(RunStatus::Failed);

            $open = $uuid ? $this->runs->findOpenByUuid($uuid) : null;

            if ($open) {
                if ($open->started_at) {
                    $changes->set('duration_ms', (int) round($open->started_at->diffInMilliseconds($now)));
                }

                $this->runs->update($open->id, $changes);

                return;
            }

            $manual = Vigilance::manualContext();
            $changes->attributes = array_merge($changes->toArray(), [
                'uuid' => $uuid ?: (string) Str::uuid(),
                'type' => RunType::Job->value,
                'name' => $class,
                'display_name' => $payload['displayName'] ?? $class,
                'connection_name' => $event->connectionName,
                'started_at' => $now,
                'via' => $manual ? 'manual' : 'auto',
                'caused_by' => $manual['user'] ?? null,
            ]);

            $this->runs->insert($changes);
        });
    }

    public function jobReleased(JobReleasedAfterException $event): void
    {
        $this->guard(function () use ($event) {
            if (! Vigilance::shouldRecord()) {
                return;
            }

            $payload = $event->job->payload();
            $uuid = $this->uuidFor($event->job, $payload);

            if (! $uuid || ! ($open = $this->runs->findOpenByUuid($uuid))) {
                return;
            }

            $this->runs->update($open->id, RunData::make([
                'attempt' => max(1, (int) $event->job->attempts()),
            ])->status(RunStatus::Released));
        });
    }

    // ---------------------------------------------------------------------
    // Commands
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $options
     */
    public function commandStarting(string $name, array $arguments, array $options): void
    {
        $this->guard(function () use ($name, $arguments, $options) {
            if (! Vigilance::shouldRecord() || Vigilance::ignoresCommand($name)) {
                return;
            }

            $manual = Vigilance::manualContext();

            $id = $this->runs->insert(
                RunData::make([
                    'uuid' => (string) Str::uuid(),
                    'name' => $name,
                    'display_name' => $name,
                    'started_at' => Carbon::now(),
                    'parameters' => Redactor::redact([
                        'arguments' => $arguments,
                        'options' => $options,
                    ]),
                    'via' => $manual ? 'manual' : 'auto',
                    'caused_by' => $manual['user'] ?? null,
                ])->type(RunType::Command)->status(RunStatus::Running)
            );

            $this->commandStack[] = [
                'id' => $id,
                'name' => $name,
                'started' => microtime(true),
                'cpu' => $this->rusage(),
            ];
        });
    }

    public function commandFinished(string $name, int $exitCode, ?string $output = null): void
    {
        $this->guard(function () use ($name, $exitCode, $output) {
            if ($this->commandStack === []) {
                return;
            }

            // Pop the most recent matching frame (commands can nest).
            $frame = null;
            foreach (array_reverse(array_keys($this->commandStack)) as $i) {
                if ($this->commandStack[$i]['name'] === $name) {
                    $frame = $this->commandStack[$i];
                    unset($this->commandStack[$i]);
                    $this->commandStack = array_values($this->commandStack);
                    break;
                }
            }

            if (! $frame) {
                return;
            }

            $cpuEnd = $this->rusage();
            $status = $exitCode === 0 ? RunStatus::Succeeded : RunStatus::Failed;
            $changes = RunData::make([
                'finished_at' => Carbon::now(),
                'duration_ms' => (int) round((microtime(true) - $frame['started']) * 1000),
                'exit_code' => $exitCode,
                'memory_peak' => $this->memory(),
                'cpu_time_ms' => ($frame['cpu'] !== null && $cpuEnd !== null) ? (int) round($cpuEnd - $frame['cpu']) : null,
            ])->status($status);

            if ($output !== null) {
                $changes->set('output', $this->truncate($output, 'max_output_length'));
            }

            if ($status === RunStatus::Failed) {
                $message = 'Command exited with code '.$exitCode;
                $changes->set('exception_message', $message);
                $changes->set('failure_group_id', $this->failures->record(
                    RunType::Command->value, $name, null, $message,
                ));
            }

            $this->runs->update($frame['id'], $changes);
        });
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?array<string, mixed>, 1: list<string>}
     */
    protected function extract(array $payload): array
    {
        if (! config('vigilance.capture.store_parameters', true)) {
            return [null, []];
        }

        $command = PayloadExtractor::command($payload);

        if (! $command) {
            return [null, []];
        }

        return [
            PayloadExtractor::parameters($command),
            TagExtractor::for($command, $payload['data']['queue'] ?? null),
        ];
    }

    /** @param array<string, mixed> $payload */
    protected function uuidFor(object $job, array $payload): ?string
    {
        if (method_exists($job, 'uuid') && $job->uuid()) {
            return $job->uuid();
        }

        return $payload['uuid'] ?? null;
    }

    protected function memory(): ?int
    {
        return config('vigilance.capture.capture_memory', true)
            ? memory_get_peak_usage(true)
            : null;
    }

    /**
     * Reduce a driver-reported queue name to its logical name so per-queue
     * grouping is consistent across drivers. Laravel's Redis driver reports the
     * queue as its storage key ("queues:default") rather than the logical name
     * ("default") used by the database/beanstalkd drivers — and by the
     * supervisor, the queue-depth probe and the supervisor config. Without this,
     * the same queue would split into two names on the dashboard and redis runs
     * would never correlate to their configured supervisor/queue.
     */
    protected function normalizeQueue(?string $connection, ?string $queue): ?string
    {
        if ($queue === null || $queue === '') {
            return $queue;
        }

        $driver = $connection !== null
            ? config("queue.connections.$connection.driver")
            : null;

        if ($driver === 'redis' && str_starts_with($queue, 'queues:')) {
            return substr($queue, 7);
        }

        return $queue;
    }

    /**
     * Total CPU time (user + system) consumed by this process so far, in
     * milliseconds. Returns null where getrusage() is unavailable (e.g. some
     * Windows builds), so CPU columns degrade gracefully rather than lie.
     */
    protected function rusage(): ?float
    {
        if (! config('vigilance.capture.capture_cpu', true) || ! function_exists('getrusage')) {
            return null;
        }

        $u = @getrusage();

        if (! is_array($u)) {
            return null;
        }

        $user = ($u['ru_utime.tv_sec'] ?? 0) + (($u['ru_utime.tv_usec'] ?? 0) / 1_000_000);
        $system = ($u['ru_stime.tv_sec'] ?? 0) + (($u['ru_stime.tv_usec'] ?? 0) / 1_000_000);

        return ($user + $system) * 1000;
    }

    /**
     * CPU milliseconds consumed by a job between its processing start and now,
     * consuming the stored baseline.
     */
    protected function cpuDelta(string $uuid): ?int
    {
        $start = $this->cpuStart[$uuid] ?? null;
        unset($this->cpuStart[$uuid]);

        $end = $this->rusage();

        return ($start !== null && $end !== null) ? max(0, (int) round($end - $start)) : null;
    }

    protected function truncate(string $value, string $configKey): string
    {
        $max = (int) config("vigilance.capture.{$configKey}", 8192);

        return mb_substr($value, 0, $max);
    }

    /**
     * Run a capture callback, swallowing any error so monitoring can never
     * break the host application.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T|null
     */
    protected function guard(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug('Vigilance capture error: '.$e->getMessage());
            }

            return null;
        }
    }
}
