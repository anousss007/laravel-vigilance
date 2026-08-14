<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Http\Livewire\Concerns\ListensForUpdates;
use Vigilance\Metrics\Workload;

/**
 * Scheduled-task health table: cron, last run timing and "late" / "last run
 * failed" badges.
 */
class Schedule extends Component
{
    use ListensForUpdates;

    public function render()
    {
        return view('vigilance::pages.schedule', [
            'tasks' => app(Workload::class)->scheduledTasks(),
        ])->layout('vigilance::layout', ['title' => 'Schedule']);
    }
}
