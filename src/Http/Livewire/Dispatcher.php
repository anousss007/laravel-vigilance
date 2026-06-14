<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Control\ControlGate;
use Vigilance\Control\Exceptions\InvalidParameter;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Control\JobDispatcher;
use Vigilance\Control\JobReflector;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Vigilance;

/**
 * Dispatch an allowed job from the dashboard. Selecting a job reflects its
 * constructor into a dynamic form; submitting hands the values to the
 * JobDispatcher (which coerces, dispatches and audits).
 */
class Dispatcher extends Component
{
    public string $jobClass = '';

    /** @var array<string, mixed> */
    public array $values = [];

    public string $queue = '';

    public bool $sync = false;

    public function updatedJobClass(): void
    {
        $this->reset(['values', 'queue', 'sync']);
        $this->hydrateDefaults();
    }

    /**
     * Seed the form values with each descriptor's default so optional fields
     * are pre-filled and checkboxes have a concrete boolean.
     */
    protected function hydrateDefaults(): void
    {
        if ($this->jobClass === '') {
            return;
        }

        foreach ($this->schema() as $descriptor) {
            $name = $descriptor['name'];

            if ($descriptor['builtin'] === 'bool') {
                $this->values[$name] = (bool) ($descriptor['default'] ?? false);
            } elseif ($descriptor['has_default'] && is_scalar($descriptor['default'])) {
                $this->values[$name] = (string) $descriptor['default'];
            } else {
                $this->values[$name] = '';
            }
        }
    }

    public function dispatchJob(): void
    {
        if (! $this->controlEnabled()) {
            return;
        }

        try {
            app(JobDispatcher::class)->dispatch(
                $this->jobClass,
                $this->values,
                queued: ! $this->sync,
                queue: $this->queue !== '' ? $this->queue : null,
                user: Vigilance::currentUser(),
            );

            session()->flash('vigilance.flash', [
                'type' => 'success',
                'message' => $this->sync
                    ? 'Job dispatched synchronously.'
                    : 'Job pushed onto the queue.',
            ]);
        } catch (NotAllowed|InvalidParameter $e) {
            session()->flash('vigilance.flash', [
                'type' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function schema(): array
    {
        if ($this->jobClass === '') {
            return [];
        }

        return app(JobReflector::class)->schema($this->jobClass);
    }

    /**
     * Allowed jobs decorated with a short name and optional human label
     * (a public static $vigilanceLabel on the job class).
     *
     * @return array<int, array{class: string, short: string, label: ?string}>
     */
    protected function jobOptions(): array
    {
        $options = [];

        foreach (app(ControlGate::class)->jobs() as $class) {
            $label = null;

            if (property_exists($class, 'vigilanceLabel')) {
                $label = (string) $class::$vigilanceLabel;
            }

            $options[] = [
                'class' => $class,
                'short' => class_basename($class),
                'label' => $label,
            ];
        }

        usort($options, fn ($a, $b) => strcmp($a['short'], $b['short']));

        return $options;
    }

    protected function controlEnabled(): bool
    {
        return (bool) config('vigilance.control.enabled', false);
    }

    public function render()
    {
        return view('vigilance::pages.dispatcher', [
            'enabled' => $this->controlEnabled(),
            'jobs' => $this->controlEnabled() ? $this->jobOptions() : [],
            'schema' => $this->schema(),
            'recent' => Run::query()
                ->where('via', 'manual')
                ->where('type', RunType::Job->value)
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'name', 'status', 'queue', 'created_at', 'duration_ms']),
        ])->layout('vigilance::layout', ['title' => 'Dispatch a job']);
    }
}
