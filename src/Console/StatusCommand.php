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

        foreach ($control->pausedQueues() as $paused) {
            $until = $paused['expires_at'] !== null
                ? 'until '.date('H:i:s', $paused['expires_at'])
                : 'indefinitely';

            $this->components->twoColumnDetail(
                '  <fg=yellow>paused queue</> <fg=gray>'.$paused['connection'].' · '.$paused['queue'].'</>',
                $until,
            );
        }

        $supervisors = $state->active((int) config('vigilance.supervision.heartbeat_expire', 30));

        if ($supervisors->isEmpty()) {
            $this->components->warn('No active supervisors. Start one with "php artisan vigilance:supervise".');

            return self::SUCCESS;
        }

        $supervisors->each(function (SupervisorRecord $s) {
            $node = $s->host !== '' ? ' @ '.$s->host : '';

            $this->components->twoColumnDetail(
                $s->name.$node.' <fg=gray>('.$s->connection.' · '.$s->queues.')</>',
                $s->status.' · '.$s->processes.' worker(s)',
            );
        });

        $nodes = $supervisors->pluck('host')->unique()->count();

        $this->newLine();
        $this->components->info(
            $supervisors->sum('processes').' worker process(es) across '.
            $supervisors->count().' supervisor instance(s)'.
            ($nodes > 1 ? ' on '.$nodes.' nodes' : '').'.',
        );

        return self::SUCCESS;
    }
}
