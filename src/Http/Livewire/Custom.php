<?php

namespace Vigilance\Http\Livewire;

use Carbon\CarbonInterval;
use Livewire\Attributes\Url;
use Livewire\Component;
use Vigilance\Metrics\CustomMetrics;

/**
 * Custom business metrics recorded via Vigilance::increment() / gauge(),
 * summarised with a value and sparkline over a selectable window.
 */
class Custom extends Component
{
    #[Url(as: 'window')]
    public string $window = '24h';

    public function setWindow(string $window): void
    {
        $this->window = in_array($window, ['1h', '24h', '7d'], true) ? $window : '24h';
    }

    protected function interval(): CarbonInterval
    {
        return match ($this->window) {
            '1h' => CarbonInterval::hour(),
            '7d' => CarbonInterval::days(7),
            default => CarbonInterval::hours(24),
        };
    }

    public function render()
    {
        return view('vigilance::pages.custom-metrics', [
            'metrics' => app(CustomMetrics::class)->all($this->interval()),
        ])->layout('vigilance::layout', ['title' => 'Custom Metrics']);
    }
}
