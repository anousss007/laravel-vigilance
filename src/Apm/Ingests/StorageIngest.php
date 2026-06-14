<?php

namespace Vigilance\Apm\Ingests;

use Illuminate\Support\Collection;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;

/**
 * The default ingest: writes straight through to storage. Because the flush
 * happens in the terminate phase (after the response is sent), the synchronous
 * write never touches request latency — and no extra infrastructure is needed.
 */
class StorageIngest implements Ingest
{
    public function __construct(protected Storage $storage) {}

    public function ingest(Collection $items): void
    {
        $this->storage->store($items);
    }

    public function digest(Storage $storage): int
    {
        return 0;
    }

    public function trim(): void
    {
        $this->storage->trim();
    }
}
