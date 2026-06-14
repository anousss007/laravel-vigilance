<?php

namespace Vigilance\Apm\Events;

/**
 * Dispatched on a single elected server per tick (guarded by a cache lock), for
 * cluster-wide work that must run exactly once.
 */
class IsolatedBeat
{
    public function __construct(
        public \DateTimeInterface $time,
        public string $instance,
    ) {}
}
