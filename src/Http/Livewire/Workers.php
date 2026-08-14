<?php

namespace Vigilance\Http\Livewire;

use Illuminate\Support\Collection;
use Livewire\Component;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Control\StagedChanges;
use Vigilance\Http\Livewire\Concerns\HasTimeRange;
use Vigilance\Http\Livewire\Concerns\ListensForUpdates;
use Vigilance\Models\WorkerRecord;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Supervision\SupervisorState;

/**
 * Live view of the running supervisors and their worker processes, with
 * pause / resume / restart controls wired to the ControlPlane. The "queue:work
 * but supervised" replacement for Horizon's dashboard.
 */
class Workers extends Component
{
    use HasTimeRange;
    use ListensForUpdates;

    /**
     * Series the viewer has switched off. Kept as component state rather than
     * done in JS so it survives Livewire's re-render — a chart that resets your
     * selection every poll is worse than no toggle at all.
     *
     * @var list<string>
     */
    public array $hiddenSeries = [];

    public function mount(): void
    {
        $this->mountHasTimeRange();
    }

    public function toggleSeries(string $key): void
    {
        $this->hiddenSeries = in_array($key, $this->hiddenSeries, true)
            ? array_values(array_diff($this->hiddenSeries, [$key]))
            : [...$this->hiddenSeries, $key];
    }

    public function pause(): void
    {
        $this->act('pause', fn () => app(ControlPlane::class)->pause(), 'Supervisors paused — workers will stop processing.');
    }

    public function resume(): void
    {
        $this->act('resume', fn () => app(ControlPlane::class)->continue(), 'Supervisors resumed.');
    }

    public function restart(): void
    {
        $this->act('restart', fn () => app(ControlPlane::class)->restart(), 'Workers will restart gracefully.');
    }

    /**
     * Either apply the action now, or add it to the staged batch — the same
     * button, so the page does not grow a second set of controls for a mode
     * most installs will never turn on.
     */
    protected function act(string $action, \Closure $immediate, string $message): void
    {
        if (config('vigilance.control.staging', false)) {
            app(StagedChanges::class)->stage($action);
            $this->dispatch('vigilance-staged');
            $this->flash('Staged — review and apply it from the banner.');

            return;
        }

        $immediate();
        $this->flash($message);
    }

    protected function flash(string $message): void
    {
        session()->flash('vigilance.flash', ['type' => 'success', 'message' => $message]);
    }

    public function render()
    {
        $expire = (int) config('vigilance.supervision.heartbeat_expire', 30);

        $supervisors = app(SupervisorState::class)->active($expire);

        // Group workers by (supervisor, host) so each node's card shows only its
        // own workers — names repeat across nodes in a multi-node fleet.
        /** @var Collection<string, Collection<int, WorkerRecord>> $workers */
        $workers = WorkerRecord::query()
            ->orderBy('supervisor')
            ->orderBy('pid')
            ->get(['supervisor', 'host', 'pid', 'queue', 'connection', 'status'])
            ->groupBy(fn (WorkerRecord $w) => $w->supervisor.'@'.$w->host);

        return view('vigilance::pages.workers', [
            'control' => app(ControlPlane::class)->status(),
            'controlEnabled' => (bool) config('vigilance.supervision', true),
            'supervisors' => $supervisors,
            'workers' => $workers,
            'fleetSeries' => $this->fleetSeries(),
        ])->layout('vigilance::layout', ['title' => 'Workers']);
    }

    /**
     * Worker count over time, per pool — the record of what the autoscaler
     * actually did, which the supervisor's own state table cannot give because
     * it only ever holds "right now".
     *
     * @return list<array{key: string, label: string, hidden: bool, points: list<?int>, max: int}>
     */
    protected function fleetSeries(): array
    {
        $graph = app(Storage::class)->graph(['workers'], 'max', $this->interval());
        $series = [];

        foreach ($graph as $key => $types) {
            $decoded = json_decode((string) $key, true);
            $label = is_array($decoded) ? implode(' · ', $decoded) : (string) $key;

            // graph() returns nested Collections, not arrays. Casting one with
            // (array) yields its protected $items wrapper rather than the
            // points — and (int) of that array is 1, which is how a fleet of
            // eight silently charted as a peak of one.
            $points = $types->get('workers')?->values()->all() ?? [];
            $values = array_filter($points, fn ($v) => $v !== null);

            $series[] = [
                'key' => (string) $key,
                'label' => $label,
                'hidden' => in_array((string) $key, $this->hiddenSeries, true),
                'points' => array_map(fn ($v) => $v === null ? null : (int) $v, $points),
                'max' => $values === [] ? 0 : (int) max($values),
            ];
        }

        return $series;
    }
}
