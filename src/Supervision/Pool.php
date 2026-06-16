<?php

namespace Vigilance\Supervision;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A pool of worker processes for one (supervisor, queue) pair. Knows how to
 * scale itself up/down to a target size and to keep its workers alive.
 */
class Pool
{
    /** @var list<WorkerProcess> */
    protected array $workers = [];

    public function __construct(
        public string $key,
        protected SupervisorOptions $options,
    ) {}

    public function count(): int
    {
        return count($this->workers);
    }

    /**
     * Bring the pool to exactly $target processes (scale up by launching,
     * scale down by gracefully terminating the surplus).
     */
    public function scaleTo(int $target): void
    {
        $target = max(0, $target);

        while (count($this->workers) < $target) {
            $this->workers[] = $this->launch();
        }

        while (count($this->workers) > $target) {
            $worker = array_pop($this->workers);
            $worker->terminate($this->options->timeout);
        }

        // Fully stopping the pool (e.g. on pause): sweep up any worker that
        // escaped a per-process kill so none keep draining the queue.
        if ($target === 0) {
            $this->reap();
        }
    }

    /**
     * Restart any worker whose process has exited.
     */
    public function monitor(): void
    {
        foreach ($this->workers as $worker) {
            $worker->monitor();
        }
    }

    public function terminate(): void
    {
        foreach ($this->workers as $worker) {
            $worker->terminate($this->options->timeout);
        }

        $this->workers = [];

        $this->reap();
    }

    /**
     * Windows-only safety net: kill any of THIS supervisor's worker processes
     * that survived a per-process stop (taskkill PID races, wrapper PIDs, or a
     * worker launched in the same tick it was told to stop). Workers are matched
     * by their "#vigilance"-tagged --name, so unrelated queue:work processes are
     * never touched. POSIX relies on Process::stop()'s signal + (under systemd)
     * cgroup reaping, so this is a no-op there.
     */
    protected function reap(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        $this->killByMarker();
    }

    /**
     * Boot-time orphan sweep: kill any worker carrying THIS supervisor's
     * "#vigilance" name marker before the pool launches its own. This clears
     * workers orphaned by a master that died without reaping them — a hard
     * SIGKILL, an OOM kill, or a restart under a process manager that does not
     * tear down the worker group (e.g. Supervisor/supervisord, unlike systemd's
     * cgroup teardown). Without it, a crashed-then-restarted master would leave
     * the old workers running alongside the fresh pool (over-provisioning,
     * double-draining, stale code/config).
     *
     * Only ever safe to call when the pool holds no tracked workers of its own —
     * i.e. at boot — because it matches by the shared per-supervisor name, not by
     * PID. Best-effort and never throws.
     */
    public function reapOrphans(): void
    {
        $this->killByMarker();
    }

    /**
     * Terminate every process whose command line carries this supervisor's
     * worker-name marker. Matching is on the "#vigilance"-tagged --name, so only
     * Vigilance's own workers are ever signalled. The current process is always
     * excluded. Cross-platform; best-effort; swallows all errors.
     */
    protected function killByMarker(): void
    {
        $name = $this->options->workerName();

        if (PHP_OS_FAMILY === 'Windows') {
            $marker = '--name='.$name;

            $ps = 'Get-CimInstance Win32_Process -Filter "Name=\'php.exe\'" '
                .'| Where-Object { $_.CommandLine -like \'*'.$marker.'*\' } '
                .'| ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }';

            // -EncodedCommand (UTF-16LE base64) sidesteps all cmd.exe quoting issues.
            $utf16 = '';
            foreach (str_split($ps) as $ch) {
                $utf16 .= $ch."\x00";
            }

            @exec('powershell -NoProfile -ExecutionPolicy Bypass -EncodedCommand '.base64_encode($utf16).' 2>&1');

            return;
        }

        if (! function_exists('posix_kill') || ! function_exists('exec')) {
            return;
        }

        // Match the worker's "--name=<name>" flag, but search WITHOUT the leading
        // dashes: pgrep parses any pattern beginning with "-" as an option (even
        // after a "--" terminator), so "name=<name>" is the safe form — still
        // unique thanks to the "#vigilance" suffix the name always carries.
        $pids = [];
        @exec('pgrep -f '.escapeshellarg('name='.$name).' 2>/dev/null', $pids);

        $self = function_exists('posix_getpid') ? posix_getpid() : getmypid();
        $signal = defined('SIGTERM') ? SIGTERM : 15;

        foreach ($pids as $pid) {
            $pid = (int) trim((string) $pid);

            if ($pid > 0 && $pid !== $self) {
                @posix_kill($pid, $signal);
            }
        }
    }

    /**
     * Live worker descriptors for the heartbeat.
     *
     * @return list<array{pid: int, queue: string}>
     */
    public function descriptors(): array
    {
        $out = [];

        foreach ($this->workers as $worker) {
            $pid = $worker->pid();

            if ($pid !== null) {
                $out[] = ['pid' => $pid, 'queue' => $this->key];
            }
        }

        return $out;
    }

    /**
     * Live worker PIDs in this pool.
     *
     * @return list<int>
     */
    public function pids(): array
    {
        $out = [];

        foreach ($this->workers as $worker) {
            $pid = $worker->pid();

            if ($pid !== null) {
                $out[] = $pid;
            }
        }

        return $out;
    }

    protected function launch(): WorkerProcess
    {
        $command = array_merge(
            $this->options->niceWrapper(),
            [$this->phpBinary(), base_path('artisan')],
            $this->options->workerCommand($this->key),
        );

        $process = new Process($command, base_path());
        $process->setTimeout(null);
        $process->disableOutput();

        return (new WorkerProcess($process, $this->key))->start();
    }

    protected function phpBinary(): string
    {
        return (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
    }
}
