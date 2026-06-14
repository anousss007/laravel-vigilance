<?php

namespace Vigilance\Tracing\Contracts;

use Illuminate\Support\Collection;
use Vigilance\Tracing\Trace;

/**
 * Persists and reads back traces. The default implementation writes to the
 * Vigilance database (optionally a dedicated connection) with batched span
 * inserts.
 */
interface TraceStorage
{
    /**
     * Persist a finished trace and its spans.
     *
     * @param  array<string, mixed>  $trace  the raw trace payload assembled by the Tracer
     */
    public function store(array $trace): void;

    /**
     * Most recent traces (newest first) for the list view.
     *
     * @param  array<string, mixed>  $filters  e.g. ['type' => 'request', 'status' => 'error', 'slow' => 1000]
     * @return Collection<int, Trace>
     */
    public function recent(array $filters = [], int $limit = 50): Collection;

    /**
     * A single trace with its spans, or null if it has been trimmed.
     */
    public function find(string $id): ?Trace;

    /**
     * Delete traces (and their spans) older than the retention window.
     */
    public function trim(): void;

    /**
     * Remove all trace data.
     */
    public function purge(): void;
}
