<?php

namespace Vigilance\Http\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;
use Vigilance\Control\Exceptions\CannotRetry;
use Vigilance\Control\JobRetrier;
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

    public function render()
    {
        $run = Run::query()->findOrFail($this->runId);

        $canRetry = $run->type === RunType::Job && $run->status === RunStatus::Failed;

        return view('vigilance::pages.run-detail', [
            'run' => $run,
            'retryOf' => $run->retry_of ? $run->retryOf : null,
            'retries' => $run->retries()->orderByDesc('id')->get(),
            'canRetry' => $canRetry,
        ])->layout('vigilance::layout', ['title' => 'Run #'.$this->runId]);
    }
}
