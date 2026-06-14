<?php

namespace Vigilance\Control;

use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Support\Redactor;
use Vigilance\Vigilance;

/**
 * Dispatches an allowed job from the dashboard. Constructor arguments are built
 * from the reflected schema and the user-submitted values, the dispatch is
 * attributed to the acting user (so the captured run is tagged via='manual'),
 * and an audit entry is written.
 */
class JobDispatcher
{
    public function __construct(
        protected ControlGate $gate = new ControlGate,
        protected JobReflector $reflector = new JobReflector,
        protected TypeCoercion $coercion = new TypeCoercion,
        protected AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * @param  class-string  $jobClass
     * @param  array<string, mixed>  $values
     */
    public function dispatch(
        string $jobClass,
        array $values,
        bool $queued = true,
        ?string $queue = null,
        ?string $user = null,
    ): void {
        if (! $this->gate->isJobAllowed($jobClass)) {
            throw new NotAllowed("Job [{$jobClass}] is not allowed for manual dispatch.");
        }

        $args = $this->buildArguments($jobClass, $values);

        $job = new $jobClass(...$args);

        Vigilance::asManual($user, function () use ($job, $queued, $queue) {
            if ($queued) {
                $pending = dispatch($job);

                if ($queue !== null) {
                    $pending->onQueue($queue);
                }

                return;
            }

            dispatch_sync($job);
        });

        $this->audit->log(
            action: 'dispatch_job',
            subject: $jobClass,
            meta: [
                'queued' => $queued,
                'queue' => $queue,
                'values' => Redactor::redact($values),
            ],
            user: $user,
        );
    }

    /**
     * Build the ordered constructor argument list from the reflected schema and
     * the submitted values (coercing each into the expected type).
     *
     * @param  class-string  $jobClass
     * @param  array<string, mixed>  $values
     * @return array<int, mixed>
     */
    protected function buildArguments(string $jobClass, array $values): array
    {
        $args = [];

        foreach ($this->reflector->schema($jobClass) as $descriptor) {
            $name = $descriptor['name'];
            $submitted = $values[$name] ?? null;

            $args[] = $this->coercion->coerce($submitted, $descriptor);
        }

        return $args;
    }
}
