<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Metrics\Stats;
use Vigilance\Metrics\Workload;
use Vigilance\Models\Deployment;

/**
 * Dashboard home: window counts, a throughput sparkline, top failure groups,
 * recent failures, the slowest runs and a compact workload summary.
 */
class Overview extends Component
{
    public function render()
    {
        $stats = app(Stats::class);
        $workload = app(Workload::class);

        $queues = $workload->queues();
        $depth = 0;

        foreach ($queues as $queue) {
            $depth += (int) ($queue['depth'] ?? 0);
        }

        return view('vigilance::pages.overview', [
            'counts' => $stats->counts('24h'),
            'throughput' => $stats->throughputPerMinute(60),
            'topFailing' => $stats->topFailing(5),
            'recentFailures' => $stats->recentFailures(8),
            'slowest' => $stats->slowest(5),
            'queueCount' => count($queues),
            'totalDepth' => $depth,
            'deployments' => Deployment::query()->orderByDesc('deployed_at')->limit(6)->get(),
        ])->layout('vigilance::layout', ['title' => 'Overview']);
    }
}
