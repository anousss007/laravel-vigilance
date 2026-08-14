<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Vigilance\Apm\Contracts\Storage;
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
        $this->checkSnapshotter();

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
        } catch (Throwable $e) {
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
            $this->reportOk('Dashboard access', 'custom Vigilance::auth() callback registered');

            return;
        }

        // A "viewVigilance" Gate ability is consulted by Vigilance::check(), so
        // detect it here too (the dashboard is genuinely gated by it).
        if ($this->getLaravel()->make(Gate::class)->has('viewVigilance')) {
            $this->reportOk('Dashboard access', 'authorized by a viewVigilance gate');

            return;
        }

        app()->environment('local')
            ? $this->reportOk('Dashboard access', 'open in local (default) — define a viewVigilance gate or Vigilance::auth() before production')
            : $this->reportWarn('Dashboard access', 'no viewVigilance gate or Vigilance::auth() — locked to local (403 here); a Gate::before rule also works');
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

        // vigilance:supervise runs queue:work, which can only drain drivers that
        // implement a worker loop (pop()). sync / null and push-only,
        // run-after-response drivers (e.g. "background") are not supervisable, so
        // there is no connection to point the supervisor at.
        $supervisable = ['database', 'redis', 'sqs', 'beanstalkd'];

        if (! in_array($driver, $supervisable, true)) {
            $this->reportOk('Supervisor', "queue.default '{$queueDefault}' uses the '{$driver}' driver — not supervisable; vigilance:supervise runs ".implode('/', $supervisable).' workers (optional)');

            return;
        }

        if ($supervisorConnection !== $queueDefault) {
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

    /**
     * Is the snapshotter still running?
     *
     * This is the one gap MonitoringHealthRule structurally cannot cover: that
     * rule is evaluated *by* vigilance:snapshot, so if the snapshotter stops,
     * the rule stops with it and nothing ever reports the silence. A dead-man's
     * switch cannot live inside the process it watches.
     *
     * Each run therefore records a heartbeat, and reading it from here — a
     * separate process — closes the loop. Exit code 1 on a stale heartbeat is
     * what makes `vigilance:doctor` usable as an external uptime check.
     */
    protected function checkSnapshotter(): void
    {
        if (! config('vigilance.metrics.enabled', true)) {
            $this->reportOk('Snapshotter', 'metrics disabled — nothing to schedule');

            return;
        }

        $ranAt = $this->snapshotHeartbeat();

        if ($ranAt === null) {
            $this->reportWarn('Snapshotter', 'never ran — schedule "vigilance:snapshot" (alerts are evaluated by it, so nothing is alerting yet)');

            return;
        }

        $minutes = (int) floor((time() - $ranAt) / 60);
        $stale = max(1, (int) config('vigilance.metrics.snapshot_stale_after', 30));

        if ($minutes >= $stale) {
            $this->reportFail('Snapshotter', "last ran {$minutes} minute(s) ago — alerts have not been evaluated since, and no alert can tell you that");

            return;
        }

        $this->reportOk('Snapshotter', "last ran {$minutes} minute(s) ago");
    }

    protected function snapshotHeartbeat(): ?int
    {
        try {
            $value = app(Storage::class)->values('vigilance')->get('snapshot');

            if ($value === null) {
                return null;
            }

            $decoded = json_decode((string) $value->value, true);

            return is_array($decoded) && isset($decoded['ran_at']) ? (int) $decoded['ran_at'] : null;
        } catch (Throwable) {
            // Pre-migration, or an unreachable storage connection — the other
            // checks already report on both.
            return null;
        }
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
