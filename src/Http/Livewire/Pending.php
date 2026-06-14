<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Metrics\PendingJobs;
use Vigilance\Models\Run;

/**
 * Live contents of the queue backend (jobs waiting to be processed), per
 * connection. Browsable for the database driver; other drivers are noted.
 */
class Pending extends Component
{
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

        return view('vigilance::pages.pending', ['groups' => $groups])
            ->layout('vigilance::layout', ['title' => 'Pending']);
    }
}
