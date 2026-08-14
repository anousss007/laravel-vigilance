<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Apm\Apm;
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

        // Heartbeat for the snapshotter itself. MonitoringHealthRule runs from
        // this very command, so it can never notice its own absence — a
        // dead-man's switch cannot live inside the process it watches. Writing
        // the timestamp here gives `vigilance:doctor`, the dashboard and any
        // external uptime check something to read instead.
        app(Apm::class)->set('vigilance', 'snapshot', (string) json_encode([
            'ran_at' => time(),
        ]));

        $alerts = app(AlertManager::class)->check();

        $this->info('Metric snapshot captured.'.($alerts > 0 ? " Dispatched {$alerts} alert(s)." : ''));

        return self::SUCCESS;
    }
}
