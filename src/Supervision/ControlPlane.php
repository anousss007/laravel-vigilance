<?php

namespace Vigilance\Supervision;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The pause / continue / restart / terminate control channel, delivered through
 * cache flags that the supervise loop polls each tick. This works everywhere
 * (including Windows and shared hosting) where POSIX signals don't — signals,
 * when available, are only a fast path layered on top.
 */
class ControlPlane
{
    public const RUNNING = 'running';

    public const PAUSED = 'paused';

    public const TERMINATING = 'terminating';

    protected const PAUSED_QUEUES = 'vigilance:control:paused-queues';

    protected function cache(): Repository
    {
        return Cache::store(config('vigilance.supervision.cache_store'));
    }

    public function status(): string
    {
        return (string) $this->cache()->get('vigilance:control:status', self::RUNNING);
    }

    public function pause(): void
    {
        $this->cache()->forever('vigilance:control:status', self::PAUSED);
    }

    public function continue(): void
    {
        $this->cache()->forever('vigilance:control:status', self::RUNNING);
    }

    public function terminate(): void
    {
        $this->cache()->forever('vigilance:control:status', self::TERMINATING);
    }

    /**
     * Bump the restart token. A running supervisor remembers the token it booted
     * with and performs a rolling restart when it changes.
     */
    public function restart(): void
    {
        $this->cache()->forever('vigilance:control:restart', (string) round(microtime(true) * 1000));
    }

    public function restartToken(): ?string
    {
        $token = $this->cache()->get('vigilance:control:restart');

        return $token !== null ? (string) $token : null;
    }

    public function isPaused(): bool
    {
        return $this->status() === self::PAUSED;
    }

    public function isTerminating(): bool
    {
        return $this->status() === self::TERMINATING;
    }

    /**
     * Clear control flags — called when a fresh supervisor boots so a stale
     * "terminating"/"paused" flag from a previous run doesn't immediately stop it.
     *
     * Per-queue pauses are deliberately NOT cleared here: they express an operator
     * intent ("stop draining this queue") that should survive a worker restart or
     * deploy until it is explicitly resumed or its duration lapses.
     */
    public function reset(): void
    {
        $this->cache()->forever('vigilance:control:status', self::RUNNING);
    }

    /**
     * Pause a single queue on a connection. The supervisor stops pulling from it
     * (narrowing multi-queue pools to their still-active queues) while leaving
     * every other queue running. Pass $seconds for a timed pause that
     * auto-resumes; null pauses indefinitely.
     */
    public function pauseQueue(string $connection, string $queue, ?int $seconds = null): void
    {
        $map = $this->pausedQueueMap();
        $map[$connection][$queue] = $seconds !== null && $seconds > 0 ? time() + $seconds : null;

        $this->cache()->forever(self::PAUSED_QUEUES, $map);
    }

    public function continueQueue(string $connection, string $queue): void
    {
        $map = $this->pausedQueueMap();

        unset($map[$connection][$queue]);

        if (($map[$connection] ?? null) === []) {
            unset($map[$connection]);
        }

        $this->cache()->forever(self::PAUSED_QUEUES, $map);
    }

    public function isQueuePaused(string $connection, string $queue): bool
    {
        return array_key_exists($queue, $this->pausedQueueMap()[$connection] ?? []);
    }

    /**
     * Every currently-paused queue, oldest expiry semantics resolved: entries
     * whose timed pause has lapsed are pruned before the list is returned.
     *
     * @return list<array{connection: string, queue: string, expires_at: ?int}>
     */
    public function pausedQueues(): array
    {
        $out = [];

        foreach ($this->pausedQueueMap() as $connection => $queues) {
            foreach ($queues as $queue => $expiresAt) {
                $out[] = [
                    'connection' => (string) $connection,
                    'queue' => (string) $queue,
                    'expires_at' => $expiresAt !== null ? (int) $expiresAt : null,
                ];
            }
        }

        return $out;
    }

    /**
     * The paused-queue map, keyed [connection][queue] => expiry epoch (or null
     * for indefinite), with lapsed timed pauses pruned. The pruned copy is
     * written back so an expired pause self-heals without an operator or the
     * supervisor having to act.
     *
     * @return array<string, array<string, ?int>>
     */
    protected function pausedQueueMap(): array
    {
        $map = $this->cache()->get(self::PAUSED_QUEUES, []);

        if (! is_array($map)) {
            return [];
        }

        $now = time();
        $changed = false;

        foreach ($map as $connection => $queues) {
            if (! is_array($queues)) {
                unset($map[$connection]);
                $changed = true;

                continue;
            }

            foreach ($queues as $queue => $expiresAt) {
                if ($expiresAt !== null && (int) $expiresAt <= $now) {
                    unset($map[$connection][$queue]);
                    $changed = true;
                }
            }

            if (($map[$connection] ?? null) === []) {
                unset($map[$connection]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->cache()->forever(self::PAUSED_QUEUES, $map);
        }

        return $map;
    }
}
