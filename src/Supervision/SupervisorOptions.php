<?php

namespace Vigilance\Supervision;

/**
 * Immutable description of one supervisor: which connection/queues it serves
 * and how its worker pool should be sized and behave. Round-trips through
 * toArray()/fromArray() so it can be persisted and rebuilt across processes.
 */
class SupervisorOptions
{
    /** @param list<string> $queue */
    public function __construct(
        public string $name,
        public string $connection,
        public array $queue = ['default'],
        public string $balance = 'auto',
        public string $autoScalingStrategy = 'time',
        public int $minProcesses = 1,
        public int $maxProcesses = 1,
        public int $balanceMaxShift = 1,
        public int $balanceCooldown = 3,
        public int $maxTime = 0,
        public int $maxJobs = 0,
        public int $memory = 128,
        public int $tries = 1,
        public int $timeout = 60,
        public int $sleep = 3,
        public int $nice = 0,
        protected int $targetWaitMs = 5000,
    ) {}

    /** @param array<string, mixed> $options */
    public static function fromArray(array $options): self
    {
        $queue = $options['queue'] ?? ['default'];

        return new self(
            name: (string) ($options['name'] ?? 'supervisor'),
            connection: (string) ($options['connection'] ?? 'database'),
            queue: is_array($queue) ? array_values($queue) : array_map('trim', explode(',', (string) $queue)),
            balance: (string) ($options['balance'] ?? 'auto'),
            autoScalingStrategy: (string) ($options['auto_scaling_strategy'] ?? 'time'),
            minProcesses: (int) ($options['min_processes'] ?? 1),
            maxProcesses: (int) ($options['max_processes'] ?? 1),
            balanceMaxShift: (int) ($options['balance_max_shift'] ?? 1),
            balanceCooldown: (int) ($options['balance_cooldown'] ?? 3),
            targetWaitMs: (int) ($options['target_wait_ms'] ?? 5000),
            maxTime: (int) ($options['max_time'] ?? 0),
            maxJobs: (int) ($options['max_jobs'] ?? 0),
            memory: (int) ($options['memory'] ?? 128),
            tries: (int) ($options['tries'] ?? 1),
            timeout: (int) ($options['timeout'] ?? 60),
            sleep: (int) ($options['sleep'] ?? 3),
            nice: (int) ($options['nice'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'balance' => $this->balance,
            'auto_scaling_strategy' => $this->autoScalingStrategy,
            'min_processes' => $this->minProcesses,
            'max_processes' => $this->maxProcesses,
            'balance_max_shift' => $this->balanceMaxShift,
            'balance_cooldown' => $this->balanceCooldown,
            'target_wait_ms' => $this->targetWaitMs,
            'max_time' => $this->maxTime,
            'max_jobs' => $this->maxJobs,
            'memory' => $this->memory,
            'tries' => $this->tries,
            'timeout' => $this->timeout,
            'sleep' => $this->sleep,
            'nice' => $this->nice,
        ];
    }

    /**
     * The worker's --name, carrying a stable "#vigilance" marker so the
     * supervisor can reliably identify and reap *its own* workers (including
     * ones orphaned by a Windows taskkill race, or left by a crashed prior
     * master), without ever touching unrelated queue:work processes.
     */
    public function workerName(): string
    {
        return $this->name.'#vigilance';
    }

    public function balancing(): bool
    {
        return in_array($this->balance, ['simple', 'auto'], true);
    }

    public function autoScaling(): bool
    {
        return $this->balance === 'auto';
    }

    public function autoScaleByNumberOfJobs(): bool
    {
        return $this->autoScalingStrategy === 'size';
    }

    /**
     * Scale on the wait time jobs actually experienced, rather than on a drain
     * time estimated from backlog x average runtime. The estimate falls apart
     * exactly when it matters — heterogeneous job durations, or one slow job
     * type arriving — because an average is not a prediction.
     */
    public function autoScaleByLatency(): bool
    {
        return $this->autoScalingStrategy === 'latency';
    }

    /**
     * The wait time (ms) the fleet is being scaled to hold. At or above it the
     * whole fleet is deployed; comfortably under it, proportionally less — which
     * is what lets the pool scale back down once latency recovers.
     */
    public function targetWaitMs(): int
    {
        return max(1, $this->targetWaitMs);
    }

    /**
     * The "pools" this supervisor runs: one per queue when balancing, otherwise
     * a single pool covering all queues (keyed by the comma-joined list).
     *
     * @return list<string>
     */
    public function pools(): array
    {
        return $this->balancing() ? $this->queue : [implode(',', $this->queue)];
    }

    /**
     * The `queue:work` argument list for a given pool key (without the PHP
     * binary / artisan path, which the process layer prepends). Built as an
     * argv array — never a shell string — so it is safe on Windows too.
     *
     * @return list<string>
     */
    public function workerCommand(string $pool): array
    {
        $args = [
            'queue:work',
            $this->connection,
            '--queue='.$pool,
            '--memory='.$this->memory,
            '--sleep='.$this->sleep,
            '--timeout='.$this->timeout,
            '--tries='.$this->tries,
            '--name='.$this->workerName(),
        ];

        if ($this->maxTime > 0) {
            $args[] = '--max-time='.$this->maxTime;
        }

        if ($this->maxJobs > 0) {
            $args[] = '--max-jobs='.$this->maxJobs;
        }

        return $args;
    }

    /**
     * The `nice` prefix to launch a worker with a lowered CPU priority, or an
     * empty array when not applicable. `nice` is a POSIX tool, so it is skipped
     * on Windows (and when nice is 0).
     *
     * @return list<string>
     */
    public function niceWrapper(): array
    {
        if ($this->nice === 0 || PHP_OS_FAMILY === 'Windows') {
            return [];
        }

        return ['nice', '-n', (string) $this->nice];
    }
}
