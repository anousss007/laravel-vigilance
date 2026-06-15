<?php

namespace Vigilance\Http\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Support\Like;
use Vigilance\Vigilance;

/**
 * Filterable, paginated table of runs (newest first). Every filter is bound to
 * a URL query-string parameter so a filtered view is shareable/bookmarkable.
 */
class Runs extends Component
{
    use WithPagination;

    /** The lean column set shown in the list (excludes heavy blob columns). */
    protected const COLUMNS = [
        'id', 'uuid', 'type', 'name', 'display_name', 'status',
        'connection_name', 'queue', 'attempt', 'via',
        'started_at', 'finished_at', 'duration_ms', 'created_at',
    ];

    #[Url(as: 'type')]
    public string $type = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'queue')]
    public string $queue = '';

    #[Url(as: 'q')]
    public string $q = '';

    #[Url(as: 'tag')]
    public string $tag = '';

    #[Url(as: 'group')]
    public ?int $group = null;

    #[Url(as: 'silenced')]
    public bool $silenced = false;

    public function updating(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['type', 'status', 'queue', 'q', 'tag', 'group', 'silenced']);
        $this->resetPage();
    }

    public function render()
    {
        $runs = Run::query()
            ->select(self::COLUMNS)
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->queue !== '', fn ($query) => $query->where('queue', $this->queue))
            ->when($this->group !== null, fn ($query) => $query->where('failure_group_id', $this->group))
            ->when($this->q !== '', fn ($query) => $query->where(function ($inner) {
                $term = Like::contains($this->q);
                $inner->whereRaw('name like ? escape ?', [$term, Like::ESCAPE])
                    ->orWhereRaw('display_name like ? escape ?', [$term, Like::ESCAPE]);
            }))
            ->when($this->tag !== '', fn ($query) => $query->whereIn('id', function ($sub) {
                $sub->select('run_id')
                    ->from('vigilance_run_tags')
                    ->where('tag', $this->tag);
            }))
            ->when(! $this->silenced, function ($query) {
                foreach (Vigilance::silencedJobPatterns() as $pattern) {
                    $like = Like::fromWildcard($pattern);
                    $query->where(fn ($q) => $q->whereNull('name')->orWhereRaw('name not like ? escape ?', [$like, Like::ESCAPE]));
                }

                $tags = Vigilance::silencedTags();

                if ($tags !== []) {
                    $query->whereNotIn('id', function ($sub) use ($tags) {
                        $sub->select('run_id')->from('vigilance_run_tags')->whereIn('tag', $tags);
                    });
                }
            })
            ->orderByDesc('id')
            ->paginate(25);

        return view('vigilance::pages.runs', [
            'runs' => $runs,
            'types' => RunType::cases(),
            'statuses' => RunStatus::cases(),
        ])->layout('vigilance::layout', ['title' => 'Runs']);
    }
}
