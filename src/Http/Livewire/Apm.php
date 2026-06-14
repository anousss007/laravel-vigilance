<?php

namespace Vigilance\Http\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The APM overview shell: a period selector plus a grid of independent,
 * lazily-loaded cards (see ApmCard). The card layout lives in the publishable
 * "vigilance::apm-dashboard" view, so apps can rearrange, resize (grid spans),
 * drop, or add their own <livewire:...> cards without touching the package.
 */
class Apm extends Component
{
    #[Url(as: 'period')]
    public string $period = '1h';

    /** @return list<string> */
    public function periods(): array
    {
        return ['1h', '6h', '24h', '7d'];
    }

    public function setPeriod(string $period): void
    {
        if (in_array($period, $this->periods(), true)) {
            $this->period = $period;
        }
    }

    public function render()
    {
        return view('vigilance::pages.apm', [
            'period' => $this->period,
            'periods' => $this->periods(),
        ])->layout('vigilance::layout', ['title' => 'APM']);
    }
}
