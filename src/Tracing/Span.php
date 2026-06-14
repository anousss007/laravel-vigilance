<?php

namespace Vigilance\Tracing;

/**
 * A single timed child operation within a trace (a query, cache op, outgoing
 * HTTP call, …). Offsets/durations are microseconds relative to the trace
 * start, so the dashboard can lay spans out on a waterfall without re-deriving
 * absolute times.
 *
 * Read-side DTO. While capturing, the Tracer keeps spans as plain arrays for
 * speed (see Tracer::$current) and only hydrates these for rendering.
 */
class Span
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $type,
        public string $label,
        public int $offsetUs,
        public int $durationUs,
        public array $attributes = [],
        public ?string $parentId = null,
    ) {}

    public function durationMs(): float
    {
        return round($this->durationUs / 1000, 2);
    }

    public function offsetMs(): float
    {
        return round($this->offsetUs / 1000, 2);
    }
}
