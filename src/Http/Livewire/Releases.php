<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Http\Livewire\Concerns\ListensForUpdates;
use Vigilance\Metrics\ReleaseHealth;

/**
 * The Releases page: recent deployment markers with a health verdict — how error
 * rate and latency moved in the window after each deploy versus before it — so a
 * bad release is obvious at a glance and (via the deploy_regression alert) pages
 * you to roll back.
 */
class Releases extends Component
{
    use ListensForUpdates;

    public function render()
    {
        return view('vigilance::pages.releases', [
            'releases' => app(ReleaseHealth::class)->recent(25),
            'windowMinutes' => (int) config('vigilance.release_health.window_minutes', 30),
        ])->layout('vigilance::layout', ['title' => 'Releases']);
    }
}
