<?php

namespace Vigilance\Control;

use Illuminate\Support\Facades\Artisan;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Enums\RunType;
use Vigilance\Jobs\RunArtisanCommand;
use Vigilance\Models\Run;
use Vigilance\Support\Redactor;
use Vigilance\Vigilance;

/**
 * Runs an allowed artisan command from the dashboard — either synchronously
 * (capturing exit code and output inline) or by pushing a RunArtisanCommand job
 * onto the queue. Either way the action is attributed to the acting user and
 * recorded in the audit trail.
 */
class CommandRunner
{
    public function __construct(
        protected ControlGate $gate = new ControlGate,
        protected AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $options
     * @return array{queued: true}|array{queued: false, exit_code: int, output: string}
     */
    public function run(
        string $name,
        array $arguments = [],
        array $options = [],
        bool $queued = false,
        ?string $user = null,
    ): array {
        if (! $this->gate->isCommandAllowed($name)) {
            throw new NotAllowed("Command [{$name}] is not allowed to run from the dashboard.");
        }

        if ($queued) {
            Vigilance::asManual($user, fn () => RunArtisanCommand::dispatch($name, $arguments, $options));

            $this->auditRun($name, $arguments, $options, true, $user);

            return ['queued' => true];
        }

        $input = $this->buildInput($arguments, $options);

        $result = Vigilance::asManual($user, function () use ($name, $input, $user) {
            $code = Artisan::call($name, $input);
            $output = Artisan::output();

            // The CommandStarting/Finished events recorded the run; enrich the
            // most recent matching Command run with output and attribution.
            $this->enrichRun($name, $output, $user);

            return ['exit_code' => $code, 'output' => $output];
        });

        $this->auditRun($name, $arguments, $options, false, $user);

        return [
            'queued' => false,
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
        ];
    }

    /**
     * Find the run that the CommandStarting/Finished events just recorded for
     * this command and attach its output (truncated) and the acting user.
     */
    protected function enrichRun(string $name, string $output, ?string $user): void
    {
        try {
            $run = Run::query()
                ->where('type', RunType::Command->value)
                ->where('name', $name)
                ->latest('id')
                ->first();

            if ($run === null) {
                return;
            }

            $max = (int) config('vigilance.capture.max_output_length', 16384);

            $run->forceFill([
                'output' => mb_substr($output, 0, $max),
                'caused_by' => $user,
            ])->save();
        } catch (\Throwable) {
            // Never let enrichment break the command run.
        }
    }

    /**
     * Build the Artisan::call input array: arguments by name, options prefixed
     * with "--".
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function buildInput(array $arguments, array $options): array
    {
        $input = $arguments;

        foreach ($options as $key => $value) {
            $input['--'.ltrim((string) $key, '-')] = $value;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $options
     */
    protected function auditRun(string $name, array $arguments, array $options, bool $queued, ?string $user): void
    {
        $this->audit->log(
            action: 'run_command',
            subject: $name,
            meta: [
                'arguments' => Redactor::redact($arguments),
                'options' => Redactor::redact($options),
                'queued' => $queued,
            ],
            user: $user,
        );
    }
}
