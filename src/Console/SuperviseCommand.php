<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Metrics\QueueDepth;
use Vigilance\Supervision\AutoScaler;
use Vigilance\Supervision\ControlPlane;
use Vigilance\Supervision\ProvisioningPlan;
use Vigilance\Supervision\QueueRuntime;
use Vigilance\Supervision\Supervisor;
use Vigilance\Supervision\SupervisorState;

class SuperviseCommand extends Command
{
    protected $signature = 'vigilance:supervise';

    protected $description = 'Run and auto-scale your queue workers (the Vigilance supervisor — replaces queue:work).';

    public function handle(AutoScaler $scaler, SupervisorState $state, ControlPlane $control, QueueDepth $depth, QueueRuntime $runtime): int
    {
        $control->reset();

        $plan = ProvisioningPlan::get()->toSupervisorOptions();

        if ($plan === []) {
            $this->components->warn('No supervisors configured for the ['.app()->environment().'] environment (see config/vigilance.php).');

            return self::SUCCESS;
        }

        $supervisors = array_map(
            fn ($options) => new Supervisor($options, $scaler, $state, $control, $depth, $runtime),
            array_values($plan),
        );

        $this->components->info('Vigilance is supervising '.count($supervisors).' supervisor(s) in ['.app()->environment().']. Use vigilance:terminate to stop.');

        $running = true;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function () use (&$running) {
                $running = false;
            };
            if (defined('SIGTERM')) {
                pcntl_signal(SIGTERM, $stop);
            }
            if (defined('SIGINT')) {
                pcntl_signal(SIGINT, $stop);
            }
        }

        while (true) {
            foreach ($supervisors as $i => $supervisor) {
                if (! $supervisor->tick()) {
                    unset($supervisors[$i]);
                }
            }

            if ($supervisors === [] || ! $running) {
                break;
            }

            sleep(1);
        }

        foreach ($supervisors as $supervisor) {
            $supervisor->terminate();
        }

        $this->components->info('Vigilance supervisor stopped.');

        return self::SUCCESS;
    }
}
