<?php

namespace Vigilance\Http\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Vigilance\Http\Livewire\Concerns\ListensForUpdates;
use Vigilance\Models\Incident;

/**
 * Incident timeline: alerts that opened an incident, with level, duration
 * (MTTR once resolved) and occurrence count. Open incidents can be resolved
 * manually; they otherwise auto-resolve when the alert stops recurring.
 */
class Incidents extends Component
{
    use ListensForUpdates;
    use WithPagination;

    #[Url(as: 'tab')]
    public string $tab = 'open';

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['open', 'resolved', 'all'], true) ? $tab : 'open';
        $this->resetPage();
    }

    public function resolve(int $id): void
    {
        Incident::query()->whereKey($id)->whereNull('resolved_at')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        session()->flash('vigilance.flash', ['type' => 'success', 'message' => 'Incident resolved.']);
    }

    public function render()
    {
        $incidents = Incident::query()
            ->when($this->tab === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($this->tab === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('vigilance::pages.incidents', [
            'incidents' => $incidents,
            'openCount' => Incident::query()->whereNull('resolved_at')->count(),
        ])->layout('vigilance::layout', ['title' => 'Incidents']);
    }
}
