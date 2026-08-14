<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Http\Livewire\Concerns\HasTimeRange;
use Vigilance\Http\Livewire\Concerns\ListensForUpdates;
use Vigilance\Metrics\WebVitals;

/**
 * Per-page Core Web Vitals (p75 LCP/INP/CLS/FCP/TTFB) from RUM beacons.
 */
class Vitals extends Component
{
    use HasTimeRange;
    use ListensForUpdates;

    public function mount(): void
    {
        $this->mountHasTimeRange();
    }

    /** A p75 over 15 minutes of beacons says nothing; vitals need volume. */
    protected function defaultRange(): string
    {
        return '24h';
    }

    public function render()
    {
        return view('vigilance::pages.vitals', [
            'pages' => app(WebVitals::class)->forInterval($this->interval()),
            'rumEnabled' => (bool) config('vigilance.rum.enabled', false),
        ])->layout('vigilance::layout', ['title' => 'Web Vitals']);
    }
}
