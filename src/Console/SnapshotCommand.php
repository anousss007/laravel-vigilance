<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Metrics\Snapshotter;
use Vigilance\Notifications\AlertManager;

class SnapshotCommand extends Command
{
    protected $signature = 'vigilance:snapshot';

    protected $description = 'Capture a throughput/runtime/wait-time metric snapshot for jobs and queues.';

    public function handle(Snapshotter $snapshotter): int
    {
        if (! config('vigilance.metrics.enabled', true)) {
            $this->warn('Vigilance metrics are disabled (config vigilance.metrics.enabled).');

            return self::SUCCESS;
        }

        $snapshotter->take();

        $alerts = app(AlertManager::class)->check();

        $this->info('Metric snapshot captured.'.($alerts > 0 ? " Dispatched {$alerts} alert(s)." : ''));

        return self::SUCCESS;
    }
}
