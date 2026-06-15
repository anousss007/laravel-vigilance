<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Metrics\Slo;

/**
 * Service-level objectives: target vs current SLI, error budget remaining and
 * burn rate, per configured objective.
 */
class Slos extends Component
{
    public function render()
    {
        return view('vigilance::pages.slos', [
            'slos' => app(Slo::class)->all(),
        ])->layout('vigilance::layout', ['title' => 'SLOs']);
    }
}
