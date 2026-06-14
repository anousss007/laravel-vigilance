<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Models\SupervisorRecord;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Supervision\SupervisorState;

class StatusCommand extends Command
{
    protected $signature = 'vigilance:status';

    protected $description = 'Show the status of running Vigilance supervisors and workers.';

    public function handle(SupervisorState $state, ControlPlane $control): int
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan;options=bold>Vigilance supervision</>', 'control: '.$control->status());

        $supervisors = $state->active((int) config('vigilance.supervision.heartbeat_expire', 30));

        if ($supervisors->isEmpty()) {
            $this->components->warn('No active supervisors. Start one with "php artisan vigilance:supervise".');

            return self::SUCCESS;
        }

        $supervisors->each(function (SupervisorRecord $s) {
            $this->components->twoColumnDetail(
                $s->name.' <fg=gray>('.$s->connection.' · '.$s->queues.')</>',
                $s->status.' · '.$s->processes.' worker(s)',
            );
        });

        $this->newLine();
        $this->components->info($supervisors->sum('processes').' worker process(es) across '.$supervisors->count().' supervisor(s).');

        return self::SUCCESS;
    }
}
