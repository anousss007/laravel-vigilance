<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Supervision\ControlPlane;

class PauseCommand extends Command
{
    protected $signature = 'vigilance:pause';

    protected $description = 'Pause all Vigilance supervisors (workers stop processing new jobs).';

    public function handle(ControlPlane $control): int
    {
        $control->pause();
        $this->components->info('Vigilance paused.');

        return self::SUCCESS;
    }
}
