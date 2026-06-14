<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Supervision\ControlPlane;

class TerminateCommand extends Command
{
    protected $signature = 'vigilance:terminate';

    protected $description = 'Gracefully stop the Vigilance supervisor and all its workers.';

    public function handle(ControlPlane $control): int
    {
        $control->terminate();
        $this->components->info('Vigilance is shutting down gracefully.');

        return self::SUCCESS;
    }
}
