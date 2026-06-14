<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Supervision\ControlPlane;

class ContinueCommand extends Command
{
    protected $signature = 'vigilance:continue';

    protected $description = 'Resume all paused Vigilance supervisors.';

    public function handle(ControlPlane $control): int
    {
        $control->continue();
        $this->components->info('Vigilance resumed.');

        return self::SUCCESS;
    }
}
