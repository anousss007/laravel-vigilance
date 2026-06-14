<?php

namespace Vigilance\Tracing;

/**
 * One root operation (HTTP request, queued job, console command, scheduled
 * task) with its child spans. Read-side DTO returned by the storage for the
 * dashboard.
 */
class Trace
{
    /**
     * @param  list<Span>  $spans
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $name,
        public string $status,
        public int $durationMs,
        public int $spanCount,
        public int $droppedSpans,
        public ?string $userId,
        public int $startedAt,
        public array $attributes = [],
        public array $spans = [],
    ) {}

    public function failed(): bool
    {
        return $this->status === 'error';
    }
}
