# Vigilance

[![Tests](https://github.com/anousss007/laravel-vigilance/actions/workflows/tests.yml/badge.svg)](https://github.com/anousss007/laravel-vigilance/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/anousss007/vigilance.svg)](https://packagist.org/packages/anousss007/vigilance)
[![PHP Version](https://img.shields.io/packagist/php-v/anousss007/vigilance.svg)](https://packagist.org/packages/anousss007/vigilance)
[![License](https://img.shields.io/packagist/l/anousss007/vigilance.svg)](LICENSE)

A driver-agnostic **control center** for Laravel queues, jobs, commands and the scheduler.

See what ran — with the parameters it ran with — whether it failed, and **dispatch jobs or run artisan commands manually** from a self-contained dashboard. Think "Horizon, but for every queue driver, plus commands, plus a manual control plane" — and built to run in production, not just locally.

> Status: early development. The capture, storage, manual-control and metrics layers are covered by tests; the dashboard ships as a standalone Livewire UI (no Filament required).

## Why Vigilance (and how it differs from Telescope / Horizon)

| | Horizon | Telescope | **Vigilance** |
|---|---|---|---|
| Queue drivers | Redis only | all | **all** (database, Redis, SQS, Beanstalkd, sync) |
| Jobs | ✅ | ✅ | ✅ (full queued → running → done/failed lifecycle) |
| Artisan commands | ❌ | ✅ (view) | ✅ (capture **and** run manually) |
| Scheduler monitoring | ❌ | partial | ✅ (late / failed / grace) |
| Manual dispatch of jobs | ❌ | ❌ | ✅ (typed form from the constructor) |
| Run arbitrary commands from UI | ❌ | ❌ | ✅ (allowlisted) |
| Failure grouping | ❌ | ❌ | ✅ (Sentry-style fingerprint) |
| Production-oriented | ✅ | ❌ (debug tool) | ✅ (see below) |

### Built for production

Telescope is a fantastic *local debugging assistant*, but it observes your whole app (requests, queries, cache, models, jobs, …), records everything by default, stores it verbatim with no size caps and no native sampling — which is why its own docs tell you to neuter it in production. Vigilance is deliberately **narrow** (only jobs / commands / scheduler) and bounded by design:

- **One row per run**, updated through its lifecycle — not a row per event.
- **Sampling decided at dispatch time**: a sampled-out *successful* job costs **zero** database writes. Failures are **always** captured regardless of the sample rate.
- **Size caps** on parameters, exception traces and command output (configurable truncation).
- **Secret redaction** by key name (`password`, `token`, …) before anything is stored.
- **Separate database connection** supported, to keep monitoring writes off your primary connection.
- **Capture is wrapped in a guard** — a monitoring error can never break the host application.
- **Master switch + per-type toggles + exclusion list** and a `ShouldNotBeMonitored` marker.
- **Retention/pruning** via `vigilance:prune`, plus ring-buffered metric snapshots.
- **Secure-by-default dashboard** (local-only until you explicitly authorize access).

## Whole-app APM (optional)

On top of jobs/commands/scheduler, Vigilance includes a **production-first APM
layer** — servers (CPU/memory/disk), slow requests, slow queries, slow outgoing
HTTP, cache hit-rate, exceptions and per-user usage — on the **APM** dashboard
page. It covers the same ground as Laravel Pulse, but driver-agnostic and with no
extra infrastructure: recorders capture cheaply (~9 µs/record), defer the heavy
work, and flush **after the response is sent**, so there is zero request latency.
A clean `Ingest` export seam lets you fan the same telemetry out to an external
APM (the groundwork for a Laravel Nightwatch integration).

Run the heartbeat on each app server and read the full design in
[docs/apm.md](docs/apm.md):

```bash
php artisan vigilance:check
```

## Tracing (optional, off by default)

For the deep dive, Vigilance can record a **per-request / per-job waterfall** —
every query, cache op and outgoing HTTP call inside a single request, with
timings — on the **Traces** page. It's the self-hosted equivalent of a hosted
APM's trace view.

Because full traces are heavy, tracing is **off by default** and engineered to
stay cheap: spans are collected in a ~2 µs in-memory push and the trace is
**persisted only if it's slow, errored, or sampled** — so at millions of queries
you store a tiny fraction, never everything, and the write happens after the
response is sent. Enable with `VIGILANCE_TRACING=true`; see
[docs/tracing.md](docs/tracing.md).

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- Livewire 3.5+ (pulled in automatically)

## Installation

```bash
composer require anousss007/vigilance
php artisan vigilance:install   # publishes config + prints next steps
php artisan migrate             # migrations are auto-loaded
```

Lock down the dashboard (it is local-only until you do this) — in any service provider's `boot()`:

```php
use Vigilance\Vigilance;

Vigilance::auth(fn ($request) => in_array($request->user()?->email, [
    'you@example.com',
]));
```

Schedule maintenance (in `routes/console.php` or your `Kernel`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vigilance:prune')->daily();
Schedule::command('vigilance:snapshot')->everyFiveMinutes();
Schedule::command('vigilance:schedule-sync')->hourly();
```

The dashboard is then at `/vigilance` (configurable).

## How capture works

Vigilance injects a correlation id into each job's payload at dispatch (`Queue::createPayloadUsing`) and listens to the framework's queue events (`JobProcessing`, `JobProcessed`, `JobFailed`, `JobReleasedAfterException`). Because it reacts to runtime events and persists to its own tables, it is **completely driver-agnostic** — the same code tracks a job whether it ran on `sync`, `database`, `redis`, `sqs` or `beanstalkd`.

Artisan commands are captured via `CommandStarting` / `CommandFinished` (name, arguments, options, exit code, duration). The scheduler is tracked via `ScheduledTask*` events, which keep a per-task monitor up to date (last run, duration, lateness, failures).

## Manual control (dispatch jobs / run commands)

The dashboard can dispatch jobs and run artisan commands with user-supplied parameters. Because that is powerful, it is governed by an **allowlist** (`config/vigilance.php` → `control`):

- **Jobs** — `mode` of `marker` (only jobs implementing `Vigilance\Contracts\Dispatchable`), `list` (explicit classes), `discover` (all `ShouldQueue` in `paths`), or `all`. The dispatch form is generated by reflecting the job's constructor (scalars, enums, dates and Eloquent models via `Model::findOrFail`).
- **Commands** — `mode` of `list` (allow names/wildcards) or `all`. A `deny` list (destructive commands like `migrate:fresh`, `db:wipe`, `tinker`, …) always wins.

Every manual dispatch / command run / retry is written to an **audit log** (who ran what, with which parameters).

Opt a job in to manual dispatch:

```php
use Vigilance\Contracts\Dispatchable;

class ProcessPodcast implements ShouldQueue, Dispatchable
{
    public static string $vigilanceLabel = 'Process a podcast';

    public function __construct(public Podcast $podcast, public bool $notify = true) {}
}
```

## Configuration

See `config/vigilance.php` — every option is documented inline. Highlights:

- `enabled`, `path`, `domain`, `middleware`
- `storage.connection` — dedicate a DB connection
- `capture.sample_rate` — fraction of successful runs to keep (failures always kept)
- `capture.store_parameters`, `capture.store_for_retry`, size caps
- `except.jobs` / `except.commands` — exclusions
- `control.jobs` / `control.commands` — manual-control allowlists
- `redact` — secret key names
- `retention.days` / `retention.failed_days` — pruning windows

### Recommended production profile

```env
VIGILANCE_SAMPLE_RATE=0.1          # keep 10% of successes; 100% of failures
VIGILANCE_DB_CONNECTION=monitoring # optional dedicated connection
VIGILANCE_RETENTION_DAYS=7
```

## Commands

| Command | Purpose |
|---|---|
| `vigilance:install` | Publish config and print next steps |
| `vigilance:prune` | Delete old runs (`--days`, `--failed-days`, `--dry-run`) and trim snapshots |
| `vigilance:snapshot` | Capture a throughput/runtime/wait-time metric snapshot |
| `vigilance:schedule-sync` | Sync defined scheduled tasks into monitors |

## Testing

```bash
composer install
./vendor/bin/pest
```

## License

MIT.
