<?php

namespace Vigilance\Notifications\Rules;

use Illuminate\Support\Carbon;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\Run;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires for jobs that have been running far longer than expected — a runaway or
 * stuck worker that queue_long_wait (which watches backlog) and the failure
 * rules (which watch completed failures) both miss, because the job never
 * finishes to report anything.
 */
class LongRunningJobRule implements AlertRule
{
    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.long_running_job.enabled', false)) {
            return;
        }

        $threshold = max(1, (int) config('vigilance.alerts.rules.long_running_job.seconds', 300));
        $limit = max(1, (int) config('vigilance.alerts.rules.long_running_job.limit', 10));
        $cutoff = Carbon::now()->subSeconds($threshold);

        $runs = Run::query()
            ->where('type', RunType::Job->value)
            ->where('status', RunStatus::Running->value)
            ->whereNotNull('started_at')
            ->where('started_at', '<', $cutoff)
            ->orderBy('started_at')
            ->limit($limit)
            ->get(['id', 'name', 'queue', 'connection_name', 'started_at']);

        foreach ($runs as $run) {
            $seconds = (int) round($run->started_at->diffInSeconds(Carbon::now()));
            $where = trim(($run->connection_name ? $run->connection_name.':' : '').($run->queue ?? ''), ':');

            yield new Alert(
                key: 'long_running_job:'.$run->id,
                title: 'Long-running job',
                message: "Job [{$run->name}] has been running for {$seconds}s"
                    .($where !== '' ? " on [{$where}]" : '')
                    .' — it may be stuck or runaway.',
                level: 'warning',
            );
        }
    }
}
