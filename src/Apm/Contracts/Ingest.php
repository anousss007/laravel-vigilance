<?php

namespace Vigilance\Apm\Contracts;

use Illuminate\Support\Collection;
use Vigilance\Apm\Entry;
use Vigilance\Apm\Value;

/**
 * Receives buffered APM entries/values when the request flushes. The default
 * implementation writes straight through to Storage on the terminate phase
 * (no infra needed); a write-behind implementation may buffer and let a worker
 * digest them.
 */
interface Ingest
{
    /**
     * @param  Collection<int, Entry|Value>  $items
     */
    public function ingest(Collection $items): void;

    /**
     * Drain any buffered items into storage. Returns the number processed.
     * (No-op for write-through ingests.)
     */
    public function digest(Storage $storage): int;

    public function trim(): void;
}
