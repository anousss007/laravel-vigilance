<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Vigilance\Apm\Recorders\Concerns\Groups;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\Sampling;

/**
 * Records cache hits and misses (grouped key), powering the hit/miss ratio card.
 */
class CacheInteractions extends Recorder
{
    use Groups;
    use Ignores;
    use Sampling;

    /** @var list<class-string> */
    public array $listen = [CacheHit::class, CacheMissed::class];

    public function record(CacheHit|CacheMissed $event): void
    {
        $type = $event instanceof CacheHit ? 'cache_hit' : 'cache_miss';
        $key = $event->key;

        $this->apm->lazy(function () use ($type, $key) {
            if ($this->shouldIgnore($key) || ! $this->shouldSample()) {
                return;
            }

            $this->apm->record($type, $this->group($key))->count();
        });
    }
}
