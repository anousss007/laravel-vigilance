<?php

namespace Vigilance\Apm\Ingests;

use Illuminate\Support\Collection;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;

/**
 * No-op ingest used when APM is disabled.
 */
class NullIngest implements Ingest
{
    public function ingest(Collection $items): void
    {
        //
    }

    public function digest(Storage $storage): int
    {
        return 0;
    }

    public function trim(): void
    {
        //
    }
}
