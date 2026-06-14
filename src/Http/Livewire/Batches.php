<?php

namespace Vigilance\Http\Livewire;

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Livewire\Component;

/**
 * Batch monitoring: reads Laravel's own batch repository (the job_batches
 * table), shows progress, and can cancel a running batch or retry its failed
 * jobs. Works on any queue driver.
 */
class Batches extends Component
{
    public function cancel(string $batchId): void
    {
        $batch = rescue(fn () => Bus::findBatch($batchId), null, false);

        if ($batch !== null && ! $batch->cancelled() && ! $batch->finished()) {
            rescue(fn () => $batch->cancel(), null, false);
            session()->flash('vigilance.flash', ['type' => 'success', 'message' => 'Batch cancelled.']);
        }
    }

    public function retry(string $batchId): void
    {
        rescue(fn () => Artisan::call('queue:retry-batch', ['id' => $batchId]), null, false);
        session()->flash('vigilance.flash', ['type' => 'success', 'message' => 'Queued the batch\'s failed jobs for retry.']);
    }

    public function render()
    {
        [$batches, $supported] = $this->load();

        return view('vigilance::pages.batches', [
            'batches' => $batches,
            'supported' => $supported,
        ])->layout('vigilance::layout', ['title' => 'Batches']);
    }

    /**
     * @return array{0: Collection<int, Batch>, 1: bool}
     */
    protected function load(): array
    {
        try {
            $batches = app(BatchRepository::class)->get(50, null);

            return [new Collection($batches), true];
        } catch (\Throwable) {
            // job_batches table not set up / driver doesn't support it.
            return [new Collection, false];
        }
    }
}
