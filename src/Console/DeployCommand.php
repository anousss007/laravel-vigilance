<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Vigilance\Models\Deployment;

/**
 * Records a deployment marker so the dashboard can correlate metric/error
 * changes with releases. Run it from your deploy script, e.g.
 *   php artisan vigilance:deploy --version=v1.4.0 --commit=$(git rev-parse HEAD)
 */
class DeployCommand extends Command
{
    protected $signature = 'vigilance:deploy
        {--release= : The release version/tag}
        {--commit= : The deployed commit SHA (auto-detected from git when omitted)}
        {--notes= : Free-form notes about the deploy}';

    protected $description = 'Record a deployment marker.';

    public function handle(): int
    {
        $deployment = Deployment::query()->create([
            'version' => $this->option('release'),
            'commit' => $this->option('commit') ?: $this->detectCommit(),
            'environment' => app()->environment(),
            'notes' => $this->option('notes'),
            'deployed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);

        $this->components->info('Recorded deployment ['.$deployment->label().'] in ['.app()->environment().'].');

        return self::SUCCESS;
    }

    protected function detectCommit(): ?string
    {
        if (! function_exists('exec') || ! is_dir(base_path('.git'))) {
            return null;
        }

        try {
            $sha = trim((string) @exec('git -C '.escapeshellarg(base_path()).' rev-parse HEAD'));

            return $sha !== '' ? $sha : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
