<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Vigilance\Control\ControlGate;
use Vigilance\Vigilance;

class DoctorCommand extends Command
{
    protected $signature = 'vigilance:doctor';

    protected $description = 'Diagnose the Vigilance installation and surface common misconfigurations.';

    protected bool $failed = false;

    public function handle(ControlGate $gate): int
    {
        $this->newLine();
        $this->components->info('Vigilance '.Vigilance::$version.' — diagnostics');

        $this->checkEnabled();
        $this->checkMigrations();
        $this->checkAuthGate();
        $this->checkControl($gate);
        $this->checkSampling();
        $this->checkTracing();
        $this->checkSupervision();
        $this->checkStorage();

        $this->newLine();
        $this->components->bulletList([
            'Schedule "vigilance:prune", "vigilance:snapshot" and "vigilance:schedule-sync" to keep data fresh and bounded.',
            'Dashboard: /'.config('vigilance.path', 'vigilance'),
        ]);

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    protected function checkEnabled(): void
    {
        config('vigilance.enabled')
            ? $this->reportOk('Monitoring', 'enabled')
            : $this->reportWarn('Monitoring', 'disabled (VIGILANCE_ENABLED=false) — nothing is being recorded');
    }

    protected function checkMigrations(): void
    {
        try {
            $exists = Schema::connection(config('vigilance.storage.connection') ?: null)->hasTable('vigilance_runs');
        } catch (\Throwable $e) {
            $this->reportFail('Migrations', 'could not reach the database: '.$e->getMessage());

            return;
        }

        $exists
            ? $this->reportOk('Migrations', 'tables present')
            : $this->reportFail('Migrations', 'not run — execute "php artisan migrate"');
    }

    protected function checkAuthGate(): void
    {
        if (Vigilance::hasCustomAuth()) {
            $this->reportOk('Dashboard access', 'custom authorization callback registered');

            return;
        }

        app()->environment('local')
            ? $this->reportOk('Dashboard access', 'open in local (default) — define a viewVigilance gate or Vigilance::auth() before production')
            : $this->reportWarn('Dashboard access', 'default gate — locked to local (403 here) until you define a viewVigilance gate, a Gate::before rule, or Vigilance::auth()');
    }

    protected function checkControl(ControlGate $gate): void
    {
        if (! config('vigilance.control.enabled')) {
            $this->reportOk('Manual control', 'disabled');

            return;
        }

        $jobs = count($gate->jobs());
        $commands = count($gate->commands());

        ($jobs === 0 && $commands === 0)
            ? $this->reportWarn('Manual control', 'enabled but nothing is allowlisted (config vigilance.control)')
            : $this->reportOk('Manual control', "{$jobs} job(s), {$commands} command(s) allowed");

        // Explain allowlisted commands that were silently overridden, so the
        // operator isn't left wondering why something they allowed vanished.
        $dropped = $gate->droppedCommands();

        if ($dropped !== []) {
            $summary = implode(', ', array_map(
                fn (string $name, string $reason) => "{$name} ({$reason})",
                array_keys($dropped),
                array_values($dropped),
            ));

            $this->reportWarn('Manual control', count($dropped).' allowlisted command(s) overridden: '.$summary);
        }
    }

    protected function checkTracing(): void
    {
        if (! config('vigilance.tracing.enabled', false)) {
            $this->reportOk('Tracing', 'disabled');

            return;
        }

        $rate = (float) config('vigilance.tracing.sample_rate', 0);
        $slow = (int) config('vigilance.tracing.slow_threshold', 1000);

        $rate <= 0.0
            ? $this->reportWarn('Tracing', "enabled, sample_rate 0 — only slow (>={$slow}ms) and errored traces are kept; raise VIGILANCE_TRACING_SAMPLE to capture normal ones")
            : $this->reportOk('Tracing', "enabled, keeping {$rate} of traces (slow + errored always kept)");
    }

    protected function checkSupervision(): void
    {
        $supervisorConnection = (string) config('vigilance.defaults.connection', 'database');
        $queueDefault = (string) config('queue.default', '');
        $driver = (string) config("queue.connections.{$queueDefault}.driver", $queueDefault);

        // Drivers with no persistent queue: there is nothing to supervise.
        if (in_array($driver, ['sync', 'null'], true)) {
            $this->reportWarn('Supervisor', "queue.default is '{$queueDefault}' ({$driver}) — nothing for vigilance:supervise to run (it is optional)");

            return;
        }

        if ($queueDefault !== '' && $supervisorConnection !== $queueDefault) {
            $this->reportWarn('Supervisor', "supervises '{$supervisorConnection}' but the app dispatches to queue.default '{$queueDefault}' — set VIGILANCE_SUPERVISOR_CONNECTION={$queueDefault} or align them");

            return;
        }

        $this->reportOk('Supervisor', "connection '{$supervisorConnection}' (matches queue.default)");
    }

    protected function checkSampling(): void
    {
        $rate = (float) config('vigilance.capture.sample_rate', 1.0);

        if ($rate >= 1.0) {
            $this->reportOk('Sampling', 'recording every run (rate 1.0)');
        } elseif ($rate <= 0.0) {
            $this->reportWarn('Sampling', 'rate 0.0 — only failures are recorded');
        } else {
            $this->reportOk('Sampling', "keeping {$rate} of successful runs (failures always kept)");
        }
    }

    protected function checkStorage(): void
    {
        $connection = config('vigilance.storage.connection') ?: config('database.default');
        $this->reportOk('Storage connection', (string) $connection);
    }

    protected function reportOk(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, '<fg=green>'.$detail.'</>');
    }

    protected function reportWarn(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, '<fg=yellow>'.$detail.'</>');
    }

    protected function reportFail(string $label, string $detail): void
    {
        $this->failed = true;
        $this->components->twoColumnDetail($label, '<fg=red>'.$detail.'</>');
    }
}
