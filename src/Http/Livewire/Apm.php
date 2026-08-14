<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Http\Livewire\Concerns\HasTimeRange;

/**
 * The APM overview shell: a range selector plus a grid of independent,
 * lazily-loaded cards (see ApmCard). The card layout lives in the publishable
 * "vigilance::apm-dashboard" view, so apps can rearrange, resize (grid spans),
 * drop, or add their own <livewire:...> cards without touching the package.
 */
class Apm extends Component
{
    use HasTimeRange;

    public function mount(): void
    {
        $this->mountHasTimeRange();
    }

    public function render()
    {
        return view('vigilance::pages.apm', [
            // The card layout is publishable, so an app may already have a copy
            // that passes :period="$period" to each card. Keep feeding that
            // exact variable rather than renaming it out from under them.
            'period' => $this->range,
        ])->layout('vigilance::layout', ['title' => 'APM']);
    }
}
