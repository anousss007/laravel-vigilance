<?php

namespace Vigilance\Apm\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Events\IsolatedBeat;
use Vigilance\Apm\Events\SharedBeat;

/**
 * The Vigilance APM heartbeat. Runs once per second on every app server: it
 * dispatches a SharedBeat (server-local recorders, e.g. CPU/memory) on every
 * tick, an IsolatedBeat (cluster-wide single-run work) on whichever server wins
 * a short cache lock, then flushes the APM buffer. One process per server.
 */
class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'vigilance:check {--once : Take a single snapshot then exit}';

    /** @var string */
    protected $description = 'Capture server stats and flush APM telemetry every second (the Vigilance heartbeat).';

    public function handle(Apm $apm, Dispatcher $events): int
    {
        if (! config('vigilance.apm.enabled', true)) {
            $this->components->warn('APM is disabled (vigilance.apm.enabled). Nothing to do.');

            return self::SUCCESS;
        }

        $store = $this->cacheStore();
        $instance = Str::random(12);
        $lastRestart = $store->get('vigilance:apm:restart');
        $lock = $this->lock($store);

        $this->components->info("Vigilance heartbeat running [{$instance}]. Press Ctrl+C to stop.");

        while (true) {
            // A `vigilance:restart`-style cache bump lets a deploy gracefully cycle
            // the heartbeat without leaving a stale process behind.
            if ($lastRestart !== $store->get('vigilance:apm:restart')) {
                return self::SUCCESS;
            }

            $now = CarbonImmutable::now();

            if ($lock?->get()) {
                $events->dispatch(new IsolatedBeat($now, $instance));
            }

            $events->dispatch(new SharedBeat($now, $instance));

            $apm->ingest();

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            Sleep::until($now->addSecond());
        }
    }

    protected function cacheStore(): CacheRepository
    {
        return Cache::store(config('vigilance.supervision.cache_store'));
    }

    /**
     * A 1-second lock so exactly one server per tick runs IsolatedBeat work.
     * Null when the cache store can't provide locks (e.g. the file driver) —
     * acceptable on single-server setups where isolation is moot.
     */
    protected function lock(CacheRepository $store): ?Lock
    {
        $inner = $store->getStore();

        return $inner instanceof LockProvider
            ? $inner->lock('vigilance:apm:check', 1)
            : null;
    }
}
