<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Supervision\ControlPlane;

class RestartCommand extends Command
{
    protected $signature = 'vigilance:restart';

    protected $description = 'Gracefully restart all Vigilance workers (e.g. after a deploy).';

    public function handle(ControlPlane $control): int
    {
        $control->restart();
        $this->components->info('Vigilance workers will restart gracefully.');

        return self::SUCCESS;
    }
}
