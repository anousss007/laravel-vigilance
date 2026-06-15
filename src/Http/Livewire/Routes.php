<?php

namespace Vigilance\Http\Livewire;

use Carbon\CarbonInterval;
use Livewire\Attributes\Url;
use Livewire\Component;
use Vigilance\Metrics\RoutePerformance;

/**
 * Per-route HTTP performance: throughput, error rate, Apdex and latency
 * percentiles (p50/p95/p99) over a selectable window.
 */
class Routes extends Component
{
    #[Url(as: 'window')]
    public string $window = '1h';

    public function setWindow(string $window): void
    {
        $this->window = in_array($window, ['1h', '6h', '24h'], true) ? $window : '1h';
    }

    protected function interval(): CarbonInterval
    {
        return match ($this->window) {
            '6h' => CarbonInterval::hours(6),
            '24h' => CarbonInterval::hours(24),
            default => CarbonInterval::hour(),
        };
    }

    public function render()
    {
        return view('vigilance::pages.routes', [
            'routes' => app(RoutePerformance::class)->forInterval($this->interval()),
        ])->layout('vigilance::layout', ['title' => 'Routes']);
    }
}
