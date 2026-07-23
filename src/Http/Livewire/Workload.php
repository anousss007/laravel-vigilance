<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Control\QueueManager;
use Vigilance\Metrics\Stats;
use Vigilance\Metrics\Workload as WorkloadMetrics;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Vigilance;

/**
 * Per-queue workload: depth (database/redis only), last-hour throughput,
 * average runtime and a throughput sparkline; system load; and a per-job-class
 * performance breakdown. Also the home of the per-queue control levers —
 * pause / resume (any driver) and clear (destructive, gated by control.enabled).
 */
class Workload extends Component
{
    public function pauseQueue(string $connection, string $queue, ?int $minutes = null): void
    {
        app(QueueManager::class)->pause(
            $connection,
            $queue,
            $minutes !== null ? $minutes * 60 : null,
            Vigilance::currentUser(),
        );

        $this->flash('success', $minutes !== null
            ? "Queue [{$queue}] paused for {$minutes} min."
            : "Queue [{$queue}] paused.");
    }

    public function resumeQueue(string $connection, string $queue): void
    {
        app(QueueManager::class)->resume($connection, $queue, Vigilance::currentUser());

        $this->flash('success', "Queue [{$queue}] resumed.");
    }

    public function clearQueue(string $connection, string $queue): void
    {
        try {
            $deleted = app(QueueManager::class)->clear($connection, $queue, Vigilance::currentUser());
        } catch (NotAllowed $e) {
            $this->flash('error', $e->getMessage());

            return;
        }

        $this->flash('success', "Cleared {$deleted} job(s) from [{$queue}].");
    }

    protected function flash(string $type, string $message): void
    {
        session()->flash('vigilance.flash', ['type' => $type, 'message' => $message]);
    }

    public function render()
    {
        $workload = app(WorkloadMetrics::class);

        $paused = [];
        foreach (app(ControlPlane::class)->pausedQueues() as $entry) {
            $paused[$entry['connection'].'|'.$entry['queue']] = $entry['expires_at'];
        }

        return view('vigilance::pages.workload', [
            'queues' => $workload->queues(),
            'load' => $workload->load(),
            'jobClasses' => app(Stats::class)->byJobClass(),
            'paused' => $paused,
            'controlEnabled' => (bool) config('vigilance.control.enabled', false),
        ])->layout('vigilance::layout', ['title' => 'Workload']);
    }
}
