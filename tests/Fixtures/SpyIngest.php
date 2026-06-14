<?php

namespace Vigilance\Tests\Fixtures;

use Illuminate\Support\Collection;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;

/**
 * A test export sink that records how much it received — stands in for an
 * external APM exporter (e.g. Nightwatch) wired through the fan-out seam.
 */
class SpyIngest implements Ingest
{
    public static int $ingested = 0;

    public static bool $trimmed = false;

    public function ingest(Collection $items): void
    {
        static::$ingested += $items->count();
    }

    public function digest(Storage $storage): int
    {
        return 0;
    }

    public function trim(): void
    {
        static::$trimmed = true;
    }
}
