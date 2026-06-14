# Vigilance Tracing (per-request / per-job waterfalls)

Tracing is Vigilance's deep-dive layer: for a single request or queued job it
records a **waterfall** of every query, cache operation and outgoing HTTP call
that happened inside it, with timings. It is the self-hosted equivalent of the
trace view in a hosted APM — you open one slow request and see exactly where the
time went.

Where the [APM aggregates](apm.md) are always-on and cheap, full traces are far
heavier, so tracing is **off by default** and built to stay affordable when on.

## Turn it on

```dotenv
VIGILANCE_TRACING=true
```

By default it then keeps only the traces you would actually open — **slow**
(≥ `slow_threshold`, 1000 ms) or **errored** ones. Visit `/vigilance/traces`.

Run nothing extra: HTTP requests are traced via a prepended middleware and jobs
via the queue events. (Console-command tracing is opt-in — `tracing.capture.commands`.)

## How it stays light under load

The design goal is that tracing a high-traffic app must not cost it. The
measures, and their measured cost:

- **Zero cost when off.** With `tracing.enabled = false` no listeners or
  middleware are registered at all — there is no code on the query path.
- **Cheap collection.** When on, recording a span is a bool check plus an array
  push: **~2 µs per span** (measured). A request running 50 queries adds ~120 µs
  of in-memory work — and only if that request ends up being kept.
- **Tail-keep, not store-everything.** Spans live in an in-memory buffer during
  the request; the trace is **persisted only if it is slow, errored, or
  head-sampled** — otherwise the buffer is discarded with no I/O. At millions of
  queries you write a tiny, interesting fraction, never everything. (This is the
  mistake that makes Telescope unsafe in production.)
- **Flushed after the response.** Persisting happens in the terminate phase, so
  the DB write (**~11 ms** for a trace + 50 spans on SQLite; less on
  MySQL/Postgres) never touches request latency.
- **Hard span cap.** `max_spans` (1000) bounds each trace so a pathological N+1
  request cannot blow up memory or storage — the overflow becomes a dropped-span
  counter shown in the UI.
- **Off-primary storage.** Set `vigilance.storage.connection` to a dedicated
  connection to keep trace writes off your primary database entirely. Spans are
  batch-inserted.
- **Short retention.** Traces are trimmed to `tracing.retention` (72 h) by a
  trim lottery on write and by `vigilance:prune`.
- **Octane-safe, fully rescued.** The in-flight trace is dropped on each Octane
  request reset, and every path is wrapped so tracing can never break a request.

## Sampling

`tracing.sample_rate` controls how many *normal* (fast, successful) traces are
kept for baseline — slow and errored traces are always kept regardless.

```php
'tracing' => [
    // 0   => only slow + errored traces (the cheap default)
    // 0.01 => also keep ~1% of normal traces for a baseline
    // 1   => keep everything (development)
    'sample_rate' => 0,

    // Or per root type:
    // 'sample_rate' => ['request' => 0.01, 'job' => 0.05, 'default' => 0],

    'slow_threshold' => 1000, // ms — at/above this a trace is always kept
],
```

At scale, keep `sample_rate` at 0 (or a small fraction) and rely on the
slow/error rules: you store the traces worth debugging and almost nothing else.

## What is captured

| Root (`tracing.capture`) | Spans (`tracing.spans`) |
|---|---|
| `requests` — HTTP, via prepended middleware | `queries` — every DB query (sql + connection) |
| `jobs` — queued jobs (JobProcessing→Processed/Failed) | `cache` — hits / misses |
| `commands` — console commands (opt-in) | `http` — outgoing Laravel HTTP-client calls |

Requests whose path matches `tracing.ignore` (the dashboard itself, Livewire,
Telescope/Horizon, …) are never traced.

## Configuration reference

```php
'tracing' => [
    'enabled' => env('VIGILANCE_TRACING', false),
    'sample_rate' => env('VIGILANCE_TRACING_SAMPLE', 0),
    'slow_threshold' => (int) env('VIGILANCE_TRACING_SLOW', 1000),
    'max_spans' => 1000,
    'max_attribute_length' => 2000,
    'capture' => ['requests' => true, 'jobs' => true, 'commands' => false],
    'spans' => ['queries' => true, 'cache' => true, 'http' => true],
    'ignore' => ['#^/vigilance#', '#^/livewire/#', /* … */],
    'retention' => env('VIGILANCE_TRACING_RETENTION', '72 hours'),
    'trim' => ['lottery' => [1, 200]],
],
```
