<?php

namespace Vigilance\Http\Livewire;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Vigilance\Control\JobRetrier;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;
use Vigilance\Vigilance;

/**
 * Failure groups, tabbed by open / resolved / all, with resolve & reopen
 * actions and a 7-day occurrence sparkline per group.
 */
class Failures extends Component
{
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
        FailureGroup::query()->whereKey($id)->update(['resolved_at' => now()]);

        session()->flash('vigilance.flash', [
            'type' => 'success',
            'message' => 'Failure group resolved.',
        ]);
    }

    public function reopen(int $id): void
    {
        FailureGroup::query()->whereKey($id)->update(['resolved_at' => null]);

        session()->flash('vigilance.flash', [
            'type' => 'success',
            'message' => 'Failure group reopened.',
        ]);
    }

    public function acknowledge(int $id): void
    {
        FailureGroup::query()->whereKey($id)->update([
            'acknowledged_at' => now(),
            'assignee' => Vigilance::currentUser(),
        ]);

        $this->flash('Issue acknowledged.');
    }

    public function setPriority(int $id, string $priority): void
    {
        $priority = in_array($priority, ['low', 'normal', 'high', 'critical'], true) ? $priority : 'normal';

        FailureGroup::query()->whereKey($id)->update(['priority' => $priority]);

        $this->flash("Priority set to {$priority}.");
    }

    public function retryGroup(int $id): void
    {
        $result = app(JobRetrier::class)->retryGroup($id, Vigilance::currentUser());

        $this->flash("Retried {$result['retried']} job(s)".($result['skipped'] > 0 ? ", {$result['skipped']} skipped (no stored payload)" : '').'.');
    }

    public function retryAll(): void
    {
        $result = app(JobRetrier::class)->retryFailed(Vigilance::currentUser());

        $this->flash("Retried {$result['retried']} failed job(s)".($result['skipped'] > 0 ? ", {$result['skipped']} skipped" : '').'.');
    }

    protected function flash(string $message): void
    {
        session()->flash('vigilance.flash', ['type' => 'success', 'message' => $message]);
    }

    public function render()
    {
        $groups = FailureGroup::query()
            ->when($this->tab === 'open', fn ($query) => $query->whereNull('resolved_at'))
            ->when($this->tab === 'resolved', fn ($query) => $query->whereNotNull('resolved_at'))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->paginate(20);

        $sparklines = $this->sparklines($groups->getCollection()->pluck('id')->all());

        return view('vigilance::pages.failures', [
            'groups' => $groups,
            'sparklines' => $sparklines,
        ])->layout('vigilance::layout', ['title' => 'Failures']);
    }

    /**
     * Build a 7-point (per-day) failure count series for each group, oldest
     * first, in a single grouped query.
     *
     * @param  array<int, int>  $groupIds
     * @return array<int, array<int, int>>
     */
    protected function sparklines(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $start = Carbon::now()->subDays(6)->startOfDay();

        $rows = Run::query()
            ->failed()
            ->whereIn('failure_group_id', $groupIds)
            ->where('created_at', '>=', $start)
            ->get(['failure_group_id', 'created_at']);

        $series = [];

        foreach ($groupIds as $id) {
            $series[$id] = array_fill(0, 7, 0);
        }

        foreach ($rows as $row) {
            if ($row->failure_group_id === null || ! $row->created_at) {
                continue;
            }

            $day = (int) $start->diffInDays($row->created_at->copy()->startOfDay());

            if ($day >= 0 && $day < 7 && isset($series[$row->failure_group_id])) {
                $series[$row->failure_group_id][$day]++;
            }
        }

        return $series;
    }
}
