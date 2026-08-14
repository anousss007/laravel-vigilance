<?php

namespace Vigilance\Apm\Ingests;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Apm\Entry;
use Vigilance\Apm\Value;

/**
 * Ships the same Entry/Value feed to an external endpoint as JSON.
 *
 * This is what the FanOutIngest seam was built for: keep writing locally, and
 * additionally stream the telemetry somewhere else — an OpenTelemetry
 * collector, a Lambda, a warehouse loader, or a small shim in front of a vendor.
 *
 * Deliberately generic rather than vendor-specific. Laravel Nightwatch, the
 * obvious candidate, ingests through its own agent (NIGHTWATCH_INGEST_URI plus
 * an environment token) and publishes no third-party ingest format; a driver
 * written against a reverse-engineered protocol would break the first time they
 * changed it, while claiming to be an integration. Point this at your own
 * endpoint instead, and shape the payload there.
 *
 * Latency: exporters run inside the terminate-phase flush, so a slow endpoint
 * delays the *worker*, not the response — but it still delays it. The timeout
 * is deliberately short, and at real volume the right shape is the redis ingest
 * with `vigilance:apm-work` doing the shipping off the request path entirely.
 */
class HttpIngest implements Ingest
{
    public function ingest(Collection $items): void
    {
        $endpoint = (string) config('vigilance.apm.ingest.http.endpoint', '');

        if ($endpoint === '' || $items->isEmpty()) {
            return;
        }

        foreach ($items->chunk($this->batchSize()) as $chunk) {
            $this->send($endpoint, $chunk);
        }
    }

    /**
     * Write-through: there is nothing buffered locally to drain.
     */
    public function digest(Storage $storage): int
    {
        return 0;
    }

    public function trim(): void
    {
        // Retention belongs to whatever is on the receiving end.
    }

    /**
     * @param  Collection<int, Entry|Value>  $chunk
     */
    protected function send(string $endpoint, Collection $chunk): void
    {
        try {
            Http::withHeaders($this->headers())
                ->timeout($this->timeout())
                ->connectTimeout($this->timeout())
                // No retries on purpose: this runs on the worker's terminate
                // path, and retrying a dead endpoint would multiply the stall
                // it is already causing. Losing an export batch is the correct
                // trade against holding up the application.
                ->post($endpoint, [
                    'source' => config('app.name'),
                    'environment' => app()->environment(),
                    'release' => config('vigilance.release'),
                    'sent_at' => time(),
                    'entries' => $chunk->map(fn (Entry|Value $item) => $this->shape($item))->values()->all(),
                ]);
        } catch (Throwable) {
            // FanOutIngest already isolates exporters, but a direct
            // configuration (http as the primary driver) must be safe too.
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function shape(Entry|Value $item): array
    {
        $shape = $item->attributes();

        // Entries carry the aggregations the receiver needs to roll them up the
        // way local storage does; Values are latest-wins snapshots.
        $shape['kind'] = $item instanceof Entry ? 'entry' : 'value';

        if ($item instanceof Entry) {
            $shape['aggregations'] = $item->aggregations;
        }

        return $shape;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($token = config('vigilance.apm.ingest.http.token')) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        foreach ((array) config('vigilance.apm.ingest.http.headers', []) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }

        return $headers;
    }

    protected function timeout(): int
    {
        return max(1, (int) config('vigilance.apm.ingest.http.timeout', 2));
    }

    protected function batchSize(): int
    {
        return max(1, (int) config('vigilance.apm.ingest.http.batch', 500));
    }
}
