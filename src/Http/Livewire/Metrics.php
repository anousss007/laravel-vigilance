<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Metrics\Stats;
use Vigilance\Metrics\Workload;

/**
 * Metrics index: per-job-class and per-queue throughput / runtime, each linking
 * to a charted detail page (Horizon's /metrics, driver-agnostic).
 */
class Metrics extends Component
{
    public function render()
    {
        return view('vigilance::pages.metrics', [
            'jobs' => app(Stats::class)->byJobClass('24h', 20),
            'queues' => app(Workload::class)->queues(),
        ])->layout('vigilance::layout', ['title' => 'Metrics']);
    }
}
