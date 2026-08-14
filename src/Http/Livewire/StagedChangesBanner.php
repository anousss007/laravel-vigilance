<?php

namespace Vigilance\Http\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use Vigilance\Control\StagedChanges;

/**
 * The persistent "changes waiting" banner, shown wherever you are in the
 * dashboard — the point of staging is that you can walk away, come back, and
 * still see what you queued up.
 */
class StagedChangesBanner extends Component
{
    #[On('vigilance-staged')]
    public function refreshStaged(): void
    {
        // Re-render; the pending list is read fresh in render().
    }

    public function discard(int $index): void
    {
        app(StagedChanges::class)->discard($index);
    }

    public function clear(): void
    {
        app(StagedChanges::class)->clear();
    }

    public function apply(): void
    {
        $result = app(StagedChanges::class)->apply();

        session()->flash('vigilance.flash', [
            'type' => $result['failed'] === [] ? 'success' : 'error',
            'message' => $result['failed'] === []
                ? $result['applied'].' change(s) applied.'
                : $result['applied'].' applied, '.count($result['failed']).' failed: '
                    .implode('; ', $result['failed']),
        ]);

        $this->dispatch('vigilance-staged');
    }

    public function render()
    {
        return view('vigilance::partials.staged-changes', [
            'pending' => app(StagedChanges::class)->pending(),
        ]);
    }
}
