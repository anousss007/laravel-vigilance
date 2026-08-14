<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Control\QueueManager;
use Vigilance\Http\Livewire\Concerns\ListensForUpdates;
use Vigilance\Metrics\PendingJobs;
use Vigilance\Models\Run;
use Vigilance\Vigilance;

/**
 * Live contents of the queue backend (jobs waiting to be processed), per
 * connection. Browsable for the database driver; other drivers are noted. When
 * manual control is enabled, individual pending jobs can be cancelled from here.
 */
class Pending extends Component
{
    use ListensForUpdates;

    /**
     * Selected pending jobs, each entry "connection#id" so ids never collide
     * across connections.
     *
     * @var list<string>
     */
    public array $selected = [];

    /**
     * Cancel the selected pending jobs on the given connection (database driver
     * only). Silently no-ops when nothing on this connection is selected.
     */
    public function cancelSelected(string $connection): void
    {
        $ids = [];

        foreach ($this->selected as $entry) {
            [$conn, $id] = array_pad(explode('#', (string) $entry, 2), 2, null);

            if ($conn === $connection && $id !== null && is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        if ($ids === []) {
            $this->flash('error', 'No pending jobs selected on this connection.');

            return;
        }

        try {
            $deleted = app(QueueManager::class)->deletePending($connection, $ids, Vigilance::currentUser());
        } catch (NotAllowed $e) {
            $this->flash('error', $e->getMessage());

            return;
        }

        $this->selected = array_values(array_filter(
            $this->selected,
            fn (string $entry) => ! str_starts_with($entry, $connection.'#'),
        ));

        $this->flash('success', "Cancelled {$deleted} pending job(s).");
    }

    protected function flash(string $type, string $message): void
    {
        session()->flash('vigilance.flash', ['type' => $type, 'message' => $message]);
    }

    public function render()
    {
        $connections = Run::query()
            ->whereNotNull('connection_name')
            ->where('created_at', '>=', now()->subDay())
            ->distinct()
            ->orderBy('connection_name')
            ->pluck('connection_name');

        $pending = app(PendingJobs::class);

        $groups = $connections->map(fn (string $connection): array => [
            'connection' => $connection,
            'driver' => (string) (config("queue.connections.{$connection}.driver") ?? 'unknown'),
            'jobs' => $pending->for($connection),
        ])->all();

        return view('vigilance::pages.pending', [
            'groups' => $groups,
            'controlEnabled' => (bool) config('vigilance.control.enabled', false),
        ])->layout('vigilance::layout', ['title' => 'Pending']);
    }
}
