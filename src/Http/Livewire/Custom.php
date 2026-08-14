<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Http\Livewire\Concerns\HasTimeRange;
use Vigilance\Http\Livewire\Concerns\ListensForUpdates;
use Vigilance\Metrics\CustomMetrics;

/**
 * Custom business metrics recorded via Vigilance::increment() / gauge(),
 * summarised with a value and sparkline over a selectable window.
 */
class Custom extends Component
{
    use HasTimeRange;
    use ListensForUpdates;

    public function mount(): void
    {
        $this->mountHasTimeRange();
    }

    /** Business metrics are usually daily-shaped, not minute-shaped. */
    protected function defaultRange(): string
    {
        return '24h';
    }

    public function render()
    {
        return view('vigilance::pages.custom-metrics', [
            'metrics' => app(CustomMetrics::class)->all($this->interval()),
        ])->layout('vigilance::layout', ['title' => 'Custom Metrics']);
    }
}
