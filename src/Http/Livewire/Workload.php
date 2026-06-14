<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Metrics\Stats;
use Vigilance\Metrics\Workload as WorkloadMetrics;

/**
 * Per-queue workload: depth (database/redis only), last-hour throughput,
 * average runtime and a throughput sparkline; system load; and a per-job-class
 * performance breakdown.
 */
class Workload extends Component
{
    public function render()
    {
        $workload = app(WorkloadMetrics::class);

        return view('vigilance::pages.workload', [
            'queues' => $workload->queues(),
            'load' => $workload->load(),
            'jobClasses' => app(Stats::class)->byJobClass(),
        ])->layout('vigilance::layout', ['title' => 'Workload']);
    }
}
