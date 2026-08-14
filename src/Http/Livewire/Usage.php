<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Http\Livewire\Concerns\ListensForUpdates;
use Vigilance\Metrics\SelfUsage;

/**
 * What the monitoring costs. Vigilance measures everything about the host app
 * and, until this page, nothing about itself — which for a tool whose pitch is
 * "bounded by design" is the one number it ought to be able to show.
 */
class Usage extends Component
{
    use ListensForUpdates;

    public function render()
    {
        $usage = app(SelfUsage::class);
        $tables = $usage->tables();

        return view('vigilance::pages.usage', [
            'tables' => $tables,
            'totalRows' => array_sum(array_map(fn ($row) => $row['rows'] ?? 0, $tables)),
            'breaches' => $usage->retentionBreaches(),
            'connection' => config('vigilance.storage.connection') ?: config('database.default'),
        ])->layout('vigilance::layout', ['title' => 'Usage']);
    }
}
