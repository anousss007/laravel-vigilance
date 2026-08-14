<?php

namespace Vigilance\Http\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;
use Vigilance\Control\ControlGate;
use Vigilance\Control\Exceptions\CannotRetry;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Control\JobRetrier;
use Vigilance\Control\RunReplayer;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Vigilance;

/**
 * Full view of a single run: timing, parameters, output, exception trace and
 * retry lineage. Failed jobs can be re-dispatched from here.
 */
class RunDetail extends Component
{
    #[Locked]
    public int $runId;

    public bool $showTrace = false;

    public function mount(Run $run): void
    {
        $this->runId = $run->getKey();
    }

    public function toggleTrace(): void
    {
        $this->showTrace = ! $this->showTrace;
    }

    public function retry(): void
    {
        try {
            app(JobRetrier::class)->retry($this->runId, Vigilance::currentUser());

            session()->flash('vigilance.flash', [
                'type' => 'success',
                'message' => 'Job re-dispatched.',
            ]);
        } catch (CannotRetry $e) {
            session()->flash('vigilance.flash', [
                'type' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run this again with exactly the parameters it ran with.
     *
     * Not the same thing as retry(): that recovers work that was meant to
     * happen, this creates it a second time — so it is gated on the control
     * plane and its allowlist, and the UI asks before firing it.
     */
    public function rerun(): void
    {
        try {
            $result = app(RunReplayer::class)->replay($this->runId, Vigilance::currentUser());

            session()->flash('vigilance.flash', [
                'type' => 'success',
                'message' => $result['type'] === 'job'
                    ? 'Job re-dispatched with the same parameters.'
                    : 'Command re-run (exit code '.($result['exit_code'] ?? '?').').',
            ]);
        } catch (CannotRetry|NotAllowed $e) {
            session()->flash('vigilance.flash', [
                'type' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function render()
    {
        $run = Run::query()->findOrFail($this->runId);

        $canRetry = $run->type === RunType::Job && $run->status === RunStatus::Failed;

        return view('vigilance::pages.run-detail', [
            'run' => $run,
            'retryOf' => $run->retry_of ? $run->retryOf : null,
            'retries' => $run->retries()->orderByDesc('id')->get(),
            'canRetry' => $canRetry,
            'canRerun' => $this->canRerun($run),
        ])->layout('vigilance::layout', ['title' => 'Run #'.$this->runId]);
    }

    /**
     * Only offer the button when it would actually work — an allowlist refusal
     * discovered after clicking is a worse experience than no button.
     */
    protected function canRerun(Run $run): bool
    {
        if (! config('vigilance.control.enabled', false)) {
            return false;
        }

        if (in_array($run->status, [RunStatus::Running, RunStatus::Queued], true)) {
            return false;
        }

        $gate = app(ControlGate::class);

        return match ($run->type) {
            RunType::Job => $gate->isJobAllowed((string) $run->name),
            RunType::Command => $gate->isCommandAllowed((string) $run->name),
            default => false,
        };
    }
}
