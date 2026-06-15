<?php

namespace Vigilance\Http\Livewire;

use Carbon\CarbonInterval;
use Livewire\Attributes\Url;
use Livewire\Component;
use Vigilance\Metrics\WebVitals;

/**
 * Per-page Core Web Vitals (p75 LCP/INP/CLS/FCP/TTFB) from RUM beacons.
 */
class Vitals extends Component
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
        return view('vigilance::pages.vitals', [
            'pages' => app(WebVitals::class)->forInterval($this->interval()),
            'rumEnabled' => (bool) config('vigilance.rum.enabled', false),
        ])->layout('vigilance::layout', ['title' => 'Web Vitals']);
    }
}
