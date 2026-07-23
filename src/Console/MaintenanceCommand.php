<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Vigilance\Notifications\MaintenanceWindow;

class MaintenanceCommand extends Command
{
    protected $signature = 'vigilance:maintenance
        {--minutes=30 : Open an ad-hoc maintenance window for this many minutes}
        {--off : Close the ad-hoc maintenance window now}
        {--status : Show whether alert notifications are currently suppressed}';

    protected $description = 'Suppress alert notifications during planned maintenance (e.g. around a deploy).';

    public function handle(MaintenanceWindow $maintenance): int
    {
        if ($this->option('off')) {
            $maintenance->stop();
            $this->components->info('Maintenance window closed — alerting resumes.');

            return self::SUCCESS;
        }

        if ($this->option('status')) {
            $until = $maintenance->adHocUntil();
            $this->components->twoColumnDetail(
                'Alert notifications',
                $maintenance->active() ? '<fg=yellow>suppressed</>' : '<fg=green>active</>',
            );
            if ($until !== null) {
                $this->components->twoColumnDetail('Ad-hoc window until', Carbon::createFromTimestamp($until)->toDateTimeString());
            }

            return self::SUCCESS;
        }

        $minutes = max(1, (int) $this->option('minutes'));
        $until = $maintenance->start($minutes);

        $this->components->info("Maintenance window open for {$minutes} min (until ".Carbon::createFromTimestamp($until)->toTimeString().') — alerts suppressed.');

        return self::SUCCESS;
    }
}
