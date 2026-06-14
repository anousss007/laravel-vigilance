<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Control\CommandReflector;
use Vigilance\Control\CommandRunner as CommandRunnerService;
use Vigilance\Control\ControlGate;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Vigilance;

/**
 * Run an allowed artisan command from the dashboard. Selecting a command
 * reflects its input definition into a dynamic form; running it inline shows
 * the exit code and captured output in a terminal-style block.
 */
class CommandRunner extends Component
{
    public string $command = '';

    /** @var array<string, mixed> */
    public array $arguments = [];

    /** @var array<string, mixed> */
    public array $options = [];

    public bool $background = false;

    public ?int $exitCode = null;

    public string $output = '';

    public function updatedCommand(): void
    {
        $this->reset(['arguments', 'options', 'background', 'exitCode', 'output']);
        $this->hydrateDefaults();
    }

    /**
     * Seed each argument/option with its declared default so the form is
     * pre-populated and value-less option checkboxes start as booleans.
     */
    protected function hydrateDefaults(): void
    {
        if ($this->command === '') {
            return;
        }

        $schema = $this->schema();

        foreach ($schema['arguments'] as $argument) {
            $default = $argument['default'];
            $this->arguments[$argument['name']] = is_scalar($default) ? (string) $default : '';
        }

        foreach ($schema['options'] as $option) {
            if ($option['accept_value']) {
                $default = $option['default'];
                $this->options[$option['name']] = is_scalar($default) ? (string) $default : '';
            } else {
                $this->options[$option['name']] = false;
            }
        }
    }

    public function runCommand(): void
    {
        if (! $this->controlEnabled()) {
            return;
        }

        $this->exitCode = null;
        $this->output = '';

        try {
            $result = app(CommandRunnerService::class)->run(
                $this->command,
                $this->cleanArguments(),
                $this->cleanOptions(),
                queued: $this->background,
                user: Vigilance::currentUser(),
            );

            if ($result['queued']) {
                session()->flash('vigilance.flash', [
                    'type' => 'success',
                    'message' => 'Command queued.',
                ]);

                return;
            }

            $this->exitCode = $result['exit_code'];
            $this->output = $result['output'];

            session()->flash('vigilance.flash', [
                'type' => $this->exitCode === 0 ? 'success' : 'error',
                'message' => 'Command finished with exit code '.$this->exitCode.'.',
            ]);
        } catch (NotAllowed $e) {
            session()->flash('vigilance.flash', [
                'type' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Drop empty arguments so the command falls back to its own defaults.
     *
     * @return array<string, mixed>
     */
    protected function cleanArguments(): array
    {
        return array_filter(
            $this->arguments,
            fn ($value) => $value !== '' && $value !== null,
        );
    }

    /**
     * Keep value options that were filled in and boolean flags that are on.
     *
     * @return array<string, mixed>
     */
    protected function cleanOptions(): array
    {
        $clean = [];

        foreach ($this->options as $name => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $clean[$name] = true;
                }
            } elseif ($value !== '' && $value !== null) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    /**
     * @return array{arguments: array<int, array<string, mixed>>, options: array<int, array<string, mixed>>}
     */
    public function schema(): array
    {
        if ($this->command === '') {
            return ['arguments' => [], 'options' => []];
        }

        return app(CommandReflector::class)->schema($this->command);
    }

    protected function controlEnabled(): bool
    {
        return (bool) config('vigilance.control.enabled', false);
    }

    public function render()
    {
        return view('vigilance::pages.command-runner', [
            'enabled' => $this->controlEnabled(),
            'commands' => $this->controlEnabled() ? app(ControlGate::class)->commands() : [],
            'schema' => $this->schema(),
        ])->layout('vigilance::layout', ['title' => 'Run a command']);
    }
}
