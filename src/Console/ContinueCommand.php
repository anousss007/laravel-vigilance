<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Supervision\ControlPlane;

class ContinueCommand extends Command
{
    protected $signature = 'vigilance:continue
        {--queue= : Resume only this queue}
        {--connection= : Connection the queue lives on (defaults to vigilance.defaults.connection)}';

    protected $description = 'Resume all paused Vigilance supervisors, or a single queue with --queue.';

    public function handle(ControlPlane $control): int
    {
        $queue = $this->option('queue');

        if ($queue === null || $queue === '') {
            $control->continue();
            $this->components->info('Vigilance resumed.');

            return self::SUCCESS;
        }

        $connection = (string) ($this->option('connection') ?: config('vigilance.defaults.connection', 'database'));

        $control->continueQueue($connection, $queue);

        $this->components->info("Queue [{$connection}:{$queue}] resumed.");

        return self::SUCCESS;
    }
}
