<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Vigilance\Vigilance;

class InstallCommand extends Command
{
    protected $signature = 'vigilance:install {--provider : Also publish the App\\Providers\\VigilanceServiceProvider gate stub}';

    protected $description = 'Install Vigilance: publish config, optionally migrate, and print next steps.';

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Installing Vigilance '.Vigilance::$version);

        $this->callSilent('vendor:publish', ['--tag' => 'vigilance-config']);
        $this->components->task('Published config/vigilance.php');

        if ($this->option('provider') || $this->confirm('Publish the dashboard authorization provider stub (App\\Providers\\VigilanceServiceProvider)?', false)) {
            $this->callSilent('vendor:publish', ['--tag' => 'vigilance-provider']);
            $this->components->task('Published App\\Providers\\VigilanceServiceProvider');
        }

        if ($this->confirm('Run the Vigilance migrations now?', true)) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->components->bulletList([
            'Lock down the dashboard: call Vigilance\\Vigilance::auth(fn ($request) => /* your check */) in a service provider. It is local-only by default.',
            'Schedule maintenance in routes/console.php (or the Kernel):',
            '    Schedule::command(\'vigilance:prune\')->daily();',
            '    Schedule::command(\'vigilance:snapshot\')->everyFiveMinutes();',
            '    Schedule::command(\'vigilance:schedule-sync\')->hourly();',
            'For high-throughput production, lower VIGILANCE_SAMPLE_RATE (failures are always kept).',
            'Alerting: listen for Vigilance\\Events\\FailureRecorded to notify on failures.',
        ]);

        $this->newLine();
        $this->components->info('Dashboard: /'.config('vigilance.path', 'vigilance').'  ·  run "php artisan vigilance:doctor" to verify your setup.');

        return self::SUCCESS;
    }
}
