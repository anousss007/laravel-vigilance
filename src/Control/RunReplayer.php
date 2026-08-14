<?php

namespace Vigilance\Control;

use Vigilance\Control\Exceptions\CannotRetry;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Support\Redactor;
use Vigilance\Vigilance;

/**
 * Re-runs a completed run with exactly the parameters it ran with.
 *
 * Deliberately separate from JobRetrier, because the two are not the same act.
 * Retrying a *failed* job restores work that was supposed to happen, which is
 * why it needs no control-plane opt-in. Re-running a job that already
 * *succeeded* creates new work — the same charge, the same email, the same
 * export, a second time. That is a dispatch, so it goes through the full
 * control gate: `control.enabled` plus the job/command allowlist, exactly like
 * dispatching from the Dispatcher page.
 */
class RunReplayer
{
    public function __construct(
        protected ControlGate $gate,
        protected CommandRunner $commands,
        protected JobRetrier $retrier,
        protected AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * @return array{type: string, queued: bool, exit_code?: ?int, output?: string}
     */
    public function replay(int $runId, ?string $user = null): array
    {
        if (! config('vigilance.control.enabled', false)) {
            throw new NotAllowed('Manual control is disabled (vigilance.control.enabled).');
        }

        $run = Run::query()->find($runId);

        if ($run === null) {
            throw new CannotRetry("Run [{$runId}] not found.");
        }

        if ($run->status === RunStatus::Running || $run->status === RunStatus::Queued) {
            throw new CannotRetry("Run [{$runId}] has not finished yet.");
        }

        return match ($run->type) {
            RunType::Job => $this->replayJob($run, $user),
            RunType::Command => $this->replayCommand($run, $user),
            default => throw new CannotRetry("Run [{$runId}] is a {$run->type->value} and cannot be re-run."),
        };
    }

    /**
     * @return array{type: string, queued: bool}
     */
    protected function replayJob(Run $run, ?string $user): array
    {
        $class = (string) $run->name;

        if (! $this->gate->isJobAllowed($class)) {
            throw new NotAllowed("Job [{$class}] is not allowed to be dispatched from the dashboard.");
        }

        // Reuse the retrier's payload reconstruction: same restricted
        // unserialize limited to the original class, same "no stored payload"
        // failure mode. Duplicating it would be a second place to get the
        // unserialize hardening wrong.
        $job = $this->retrier->restore($run);

        Vigilance::asManual($user, function () use ($job, $run) {
            $pending = dispatch($job);

            if ($run->queue) {
                $pending->onQueue($run->queue);
            }

            if ($run->connection_name) {
                $pending->onConnection($run->connection_name);
            }
        });

        $this->audit->log(
            action: 'rerun',
            subject: $class,
            runId: $run->id,
            meta: ['rerun_of' => $run->id, 'connection' => $run->connection_name, 'queue' => $run->queue],
            user: $user,
        );

        return ['type' => 'job', 'queued' => true];
    }

    /**
     * @return array{type: string, queued: bool, exit_code: ?int, output: string}
     */
    protected function replayCommand(Run $run, ?string $user): array
    {
        $name = (string) $run->name;

        if (! $this->gate->isCommandAllowed($name)) {
            throw new NotAllowed("Command [{$name}] is not allowed to be run from the dashboard.");
        }

        // Parameters were captured as the command's resolved arguments and
        // options, and are stored redacted — a secret that was scrubbed on the
        // way in must not be resurrected here, so a redacted value is dropped
        // and the command falls back to its own default.
        $parameters = (array) ($run->parameters ?? []);

        $result = $this->commands->run(
            $name,
            $this->clean((array) ($parameters['arguments'] ?? [])),
            $this->clean((array) ($parameters['options'] ?? [])),
            queued: false,
            user: $user,
        );

        $this->audit->log(
            action: 'rerun',
            subject: $name,
            runId: $run->id,
            meta: ['rerun_of' => $run->id, 'exit_code' => $result['exit_code'] ?? null],
            user: $user,
        );

        return [
            'type' => 'command',
            'queued' => $result['queued'],
            'exit_code' => $result['exit_code'] ?? null,
            'output' => (string) ($result['output'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function clean(array $values): array
    {
        $redacted = Redactor::PLACEHOLDER;

        return array_filter(
            $values,
            fn ($value) => $value !== null && $value !== '' && $value !== $redacted,
        );
    }
}
