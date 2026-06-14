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
     */
    public function reset(): void
    {
        $this->cache()->forever('vigilance:control:status', self::RUNNING);
    }
}
