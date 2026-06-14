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
