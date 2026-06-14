<?php

namespace Vigilance\Supervision;

use Symfony\Component\Process\Process;

/**
 * A single worker = one OS process running the host app's `queue:work`. Wraps a
 * Symfony Process and relaunches it when it exits (max-jobs/max-time/memory
 * limits, or a crash), with a 1s cooldown so a hard-crashing worker can't spin.
 */
class WorkerProcess
{
    protected ?float $restartAgainAt = null;

    public function __construct(
        public Process $process,
        public string $queue,
    ) {}

    public function start(): self
    {
        if (! $this->process->isStarted()) {
            $this->process->start();
        }

        return $this;
    }

    public function pid(): ?int
    {
        return $this->process->getPid();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Relaunch the worker if its process has exited and it isn't cooling down.
     */
    public function monitor(): void
    {
        if ($this->process->isRunning()) {
            return;
        }

        if ($this->restartAgainAt !== null && microtime(true) < $this->restartAgainAt) {
            return;
        }

        $this->restartAgainAt = microtime(true) + 1;
        $this->process = $this->process->restart();
    }

    /**
     * Graceful stop: SIGTERM, then SIGKILL after the timeout (taskkill on
     * Windows). The worker finishes its current job first.
     */
    public function terminate(int $timeout = 10): void
    {
        try {
            if ($this->process->isRunning()) {
                $this->process->stop($timeout);
            }
        } catch (\Throwable) {
            // already gone
        }
    }
}
