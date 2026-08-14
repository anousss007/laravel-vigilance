<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Http\Livewire\Concerns\CreatesSuppressions;
use Vigilance\Http\Livewire\Concerns\HasTimeRange;
use Vigilance\Http\Livewire\Concerns\ListensForUpdates;
use Vigilance\Metrics\RoutePerformance;
use Vigilance\Models\Suppression;

/**
 * Per-route HTTP performance: throughput, error rate, Apdex and latency
 * percentiles (p50/p95/p99), plus what each page costs, over a selectable
 * window.
 */
class Routes extends Component
{
    use CreatesSuppressions;
    use HasTimeRange;
    use ListensForUpdates;

    public function mount(): void
    {
        $this->mountHasTimeRange();
    }

    public function render()
    {
        return view('vigilance::pages.routes', [
            'routes' => app(RoutePerformance::class)->forInterval($this->interval()),
            'suppressions' => $this->activeSuppressions(Suppression::SCOPE_ROUTE),
        ])->layout('vigilance::layout', ['title' => 'Routes']);
    }
}
