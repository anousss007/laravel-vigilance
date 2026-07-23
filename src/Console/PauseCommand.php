<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Supervision\ControlPlane;

class PauseCommand extends Command
{
    protected $signature = 'vigilance:pause
        {--queue= : Pause only this queue (leave the other queues running)}
        {--connection= : Connection the queue lives on (defaults to vigilance.defaults.connection)}
        {--for= : Auto-resume after this many seconds (default: pause indefinitely)}';

    protected $description = 'Pause all Vigilance supervisors, or a single queue with --queue.';

    public function handle(ControlPlane $control): int
    {
        $queue = $this->option('queue');

        if ($queue === null || $queue === '') {
            $control->pause();
            $this->components->info('Vigilance paused.');

            return self::SUCCESS;
        }

        $connection = (string) ($this->option('connection') ?: config('vigilance.defaults.connection', 'database'));
        $for = $this->option('for');
        $seconds = $for !== null && $for !== '' ? max(1, (int) $for) : null;

        $control->pauseQueue($connection, $queue, $seconds);

        $this->components->info("Queue [{$connection}:{$queue}] paused".($seconds !== null ? " for {$seconds}s." : '.'));

        return self::SUCCESS;
    }
}
