# Vigilance APM (whole-app performance monitoring)

Beyond jobs, commands and the scheduler, Vigilance ships a **whole-app APM layer**:
server health, slow requests, slow queries, slow outgoing HTTP, cache hit-rate,
exceptions and per-user usage — rolled into time-bucketed aggregates and surfaced
on the **APM** dashboard page (`/vigilance/apm`).

It is a driver-agnostic, production-first take on the same ground Laravel Pulse
covers: no Redis requirement, no separate ingest infrastructure needed, and a
clean seam for forwarding the same telemetry to an external APM.

## How it works

```
recorder (event)         Apm buffer            Ingest              Storage
─────────────────        ──────────            ──────              ───────
cheap synchronous   ▶    record()/set()   ▶    StorageIngest  ▶    vigilance_entries
capture + $apm->lazy()   (in memory)           (write-through)     vigilance_aggregates
                                                                    vigilance_values
            └── heavy work (sampling, regex, backtraces) deferred ──┘
                          flushed in the TERMINATE phase
                          (after the response is sent)
```

1. **Recorders** listen to framework events (or hook the kernel/HTTP-client) and
   do the smallest possible synchronous work — usually just a threshold check —
   then push the expensive part into `$apm->lazy()`.
2. The **Apm** coordinator buffers entries in memory. Nothing heavy runs on the
   request thread.
3. On **terminate** (after the response is flushed to the client), the buffer is
   resolved, filtered and handed to the **Ingest**, which writes through to
   **Storage**. Queue workers flush on `Looping` / `WorkerStopping`; a
   `vigilance:check` heartbeat flushes every second for server stats.
4. **Storage** pre-aggregates every entry into rolling buckets for the periods
   `[1h, 6h, 24h, 7d]`. Reads union the live "tail" (raw entries newer than the
   newest sealed bucket) with the sealed buckets, so the current partial window
   is always accurate. This is a faithful port of Pulse's aggregation model,
   driver-agnostic across MySQL/MariaDB, PostgreSQL and SQLite.

## Recorders

| Recorder | Captures | Key |
|---|---|---|
| `Servers` | CPU %, memory, disk per server (throttled) | server slug |
| `SlowRequests` | requests slower than the threshold | `[method, route]` |
| `SlowQueries` | queries slower than the threshold | `{sql, location}` |
| `SlowOutgoingRequests` | slow outgoing HTTP (global Guzzle middleware) | `[method, uri]` |
| `CacheInteractions` | cache hits / misses | grouped key |
| `Exceptions` | every reported exception | `{class, location}` |
| `UserRequests` | requests per authenticated user | user id |

Server stats are gathered cross-platform (Linux `/proc` + `sys_getloadavg`, macOS
`sysctl`/`vm_stat`, Windows PowerShell CIM) and **degrade to `0` rather than
throwing** on an unsupported host.

Run the heartbeat on each app server (one process per server):

```bash
php artisan vigilance:check
```

## Production safety

The APM layer is engineered so monitoring can **never** add meaningful latency or
break the host app:

- **Zero request latency.** The buffer is flushed in the terminate phase, after
  the response is sent. Recorders only buffer during the request.
- **Cheap hot path.** Buffering a metric costs **~9 µs** (measured, SQLite, a
  typical request records a handful) — and the heavy work (sampling, regex,
  backtraces) is deferred to flush time via `lazy()`.
- **Everything is rescued.** Every telemetry path is wrapped so a recorder error
  is swallowed (optionally routed to your handler) and never bubbles into the
  request.
- **Bounded cardinality.** Requests key by the matched *route* (not the raw
  path); cache/outgoing keys can be collapsed with `groups`; reads are capped.
- **Bounded storage.** A trim lottery on flush (1-in-1000 by default) prunes data
  older than `apm.storage.trim.keep` (7 days). `vigilance:prune` also trims the
  APM tables.
- **Per-recorder sampling & thresholds.** Each recorder takes a `sample_rate`
  (0.0–1.0) and `threshold` (ms, or a `[regex => ms]` map) to bound overhead and
  volume at scale. Turn any recorder off with `enabled => false`.
- **Memory backstop.** A request that records a pathological number of entries
  force-flushes at `apm.ingest.buffer` (5000) instead of growing unbounded.
- **Octane-aware.** The coordinator re-binds to the current request sandbox on
  every Octane tick, so it never holds a stale container.
- **Off by a flag.** `apm.enabled => false` registers nothing and records
  nothing.

Tune the knobs in `config/vigilance.php` under `apm.recorders.*` (e.g. drop the
request sample rate at high traffic):

```php
'recorders' => [
    \Vigilance\Apm\Recorders\SlowRequests::class => [
        'sample_rate' => 0.1,   // keep 10% of slow requests
        'threshold'   => 1000,  // ms, or ['#^/admin#' => 2000, 'default' => 1000]
        'ignore'      => ['#^/health$#'],
    ],
],
```

## Export seam (forwarding to an external APM / Nightwatch)

The `Ingest` contract is the single seam every metric flows through. To forward
the same `Entry`/`Value` feed to an external system, implement it and register it
as an **exporter** — it runs *alongside* the primary store via a fan-out, and a
failing exporter can never break local capture:

```php
namespace App\Apm;

use Illuminate\Support\Collection;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;

class NightwatchIngest implements Ingest
{
    public function ingest(Collection $items): void
    {
        // $items is a Collection<Entry|Value> — forward to your sink.
    }

    public function digest(Storage $storage): int
    {
        return 0; // for write-behind sinks; no-op for direct forwarders
    }

    public function trim(): void
    {
        //
    }
}
```

```php
// config/vigilance.php
'apm' => [
    'ingest' => [
        'driver'    => 'storage',                  // keep the local dashboard
        'exporters' => [\App\Apm\NightwatchIngest::class], // + forward everything
    ],
],
```

Set `ingest.driver` to `'null'` to forward only (no local storage), or to a
custom class-string to replace the primary entirely. This is the groundwork for a
first-class Laravel Nightwatch integration: the telemetry stream is already
decoupled from where it lands.
