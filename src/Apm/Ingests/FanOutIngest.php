<?php

namespace Vigilance\Apm\Ingests;

use Illuminate\Support\Collection;
use Throwable;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;

/**
 * Forwards the buffered telemetry to a primary ingest (normally storage) plus
 * any number of additional export sinks — the seam for streaming the same
 * Entry/Value feed to an external APM (e.g. a future Laravel Nightwatch
 * exporter).
 *
 * The primary keeps today's exact semantics; every exporter is strictly
 * additive and wrapped so a flaky external sink can never break local capture
 * or each other.
 */
class FanOutIngest implements Ingest
{
    /**
     * @param  list<Ingest>  $exporters
     */
    public function __construct(
        protected Ingest $primary,
        protected array $exporters,
    ) {}

    public function ingest(Collection $items): void
    {
        $this->primary->ingest($items);

        foreach ($this->exporters as $exporter) {
            try {
                $exporter->ingest($items);
            } catch (Throwable) {
                // An external sink must never break local telemetry.
            }
        }
    }

    public function digest(Storage $storage): int
    {
        $processed = $this->primary->digest($storage);

        foreach ($this->exporters as $exporter) {
            try {
                $exporter->digest($storage);
            } catch (Throwable) {
                //
            }
        }

        return $processed;
    }

    public function trim(): void
    {
        $this->primary->trim();

        foreach ($this->exporters as $exporter) {
            try {
                $exporter->trim();
            } catch (Throwable) {
                //
            }
        }
    }
}
