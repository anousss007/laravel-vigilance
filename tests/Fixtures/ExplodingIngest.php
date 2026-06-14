<?php

namespace Vigilance\Tests\Fixtures;

use Illuminate\Support\Collection;
use RuntimeException;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;

/**
 * An export sink that always throws — used to prove a flaky external exporter
 * can never break local capture.
 */
class ExplodingIngest implements Ingest
{
    public function ingest(Collection $items): void
    {
        throw new RuntimeException('exporter is down');
    }

    public function digest(Storage $storage): int
    {
        throw new RuntimeException('exporter is down');
    }

    public function trim(): void
    {
        throw new RuntimeException('exporter is down');
    }
}
