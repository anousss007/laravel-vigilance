<?php

namespace Vigilance\Apm\Events;

/**
 * Dispatched every tick of vigilance:check on EVERY server. Recorders that run
 * per-server (e.g. Servers) listen here.
 */
class SharedBeat
{
    public function __construct(
        public \DateTimeInterface $time,
        public string $instance,
    ) {}
}
