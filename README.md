# Vigilance

[![Tests](https://github.com/anousss007/laravel-vigilance/actions/workflows/tests.yml/badge.svg)](https://github.com/anousss007/laravel-vigilance/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/anousss007/vigilance.svg)](https://packagist.org/packages/anousss007/vigilance)
[![PHP Version](https://img.shields.io/packagist/php-v/anousss007/vigilance.svg)](https://packagist.org/packages/anousss007/vigilance)
[![License](https://img.shields.io/packagist/l/anousss007/vigilance.svg)](LICENSE)

A driver-agnostic **control center** for Laravel queues, jobs, commands and the scheduler.

See what ran — with the parameters it ran with — whether it failed, and **dispatch jobs or run artisan commands manually** from a self-contained dashboard. Think "Horizon, but for every queue driver, plus commands, plus a manual control plane" — and built to run in production, not just locally.

> **📖 Documentation & guide → [anousss007.github.io/laravel-vigilance](https://anousss007.github.io/laravel-vigilance/)**
>
> Published on Packagist. The capture, storage, manual-control, metrics, tracing **and worker-supervisor** layers are covered by tests — including a real multi-process supervisor **chaos battery** validated across the `database`, `redis`, `sqs` and `beanstalkd` drivers (autoscaling, crash-recovery, graceful shutdown, zero orphans). The dashboard ships as a standalone Livewire UI (no Filament required).

## Why Vigilance (and how it differs from Telescope / Horizon)

| | Horizon | Telescope | **Vigilance** |
|---|---|---|---|
| Queue drivers | Redis only | all | **all** (database, Redis, SQS, Beanstalkd, sync) |
| Jobs | ✅ | ✅ | ✅ (full queued → running → done/failed lifecycle) |
| Artisan commands | ❌ | ✅ (view) | ✅ (capture **and** run manually) |
| Scheduler monitoring | ❌ | partial | ✅ (late / failed / grace) |
| Manual dispatch of jobs | ❌ | ❌ | ✅ (typed form from the constructor) |
| Run arbitrary commands from UI | ❌ | ❌ | ✅ (allowlisted) |
| Error tracking (grouped issues) | ❌ | ✅ (view) | ✅ (web · queue · command · browser, fingerprinted inbox) |
| Whole-app APM + per-route percentiles | ❌ | ❌ | ✅ (p50/p95/p99, Apdex, error rate) |
| Real User Monitoring (Core Web Vitals) | ❌ | ❌ | ✅ (LCP/INP/CLS/FCP/TTFB + JS errors) |
| SLOs + error budgets | ❌ | ❌ | ✅ (burn-rate alerts) |
| Trace-correlated log explorer | ❌ | ✅ (view) | ✅ (searchable, linked to traces) |
| Custom business metrics | ❌ | ❌ | ✅ (one-line API + dashboard) |
| Release health / deploy gating | ❌ | ❌ | ✅ (before/after regression guard + rollback alert) |
| Anomaly detection | ❌ | ❌ | ✅ (dynamic baselines, not fixed thresholds) |
| Alerting | ❌ | ❌ | ✅ (mail · Slack · Discord · Teams · webhooks + incidents) |
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

## Observability suite

On top of capture, APM and tracing, Vigilance is a full front-to-back
observability platform. Each layer keeps the same production-first posture
(captured cheaply, flushed after the response, sampled and bounded) and lands on
its own dashboard page. Full guide in
[docs/observability.md](docs/observability.md).

| Feature | Page | What it gives you |
|---|---|---|
| **Issues** — unified error tracking | `/vigilance/issues` | Every exception (web · queue · command · `Vigilance::report()` · browser) fingerprinted into a grouped inbox with stacktrace, context, occurrence sparkline, assign/ack/mute/resolve |
| **Routes** — per-route performance | `/vigilance/routes` | Throughput, error rate, Apdex and exact **p50/p95/p99** latency per route |
| **Web Vitals** — RUM | `/vigilance/vitals` | Core Web Vitals (LCP/INP/CLS/FCP/TTFB) + JS errors from real visitors via the `@vigilanceRum` beacon |
| **SLOs** — error budgets | `/vigilance/slos` | Availability / latency objectives vs. an error budget, with a short-window **burn-rate** alert |
| **Incidents** — alerting depth | `/vigilance/incidents` | Fired alerts persisted as incidents (open → auto-resolved) with level, occurrences and **MTTR**; channels for Discord / Teams / generic webhooks |
| **Releases** — deploy health | `/vigilance/releases` | Each deploy's error-rate / latency / throughput **after vs. before**, with a healthy/degraded/**regressed** verdict; a bad deploy fires a rollback-ready alert |
| **Custom Metrics** — business KPIs | `/vigilance/custom-metrics` | `Vigilance::increment()` / `gauge()` → auto-discovered counter & gauge cards with sparklines |
| **Logs** — explorer | `/vigilance/logs` | Searchable application logs **correlated to the trace that emitted them** |

```php
use Vigilance\Vigilance;

Vigilance::increment('signups');                 // custom counter
Vigilance::gauge('cart_value', $cart->total());  // custom gauge
```

```blade
{{-- drop in your layout <head> after VIGILANCE_RUM=true to collect Web Vitals --}}
@vigilanceRum
```

## Requirements

- PHP 8.2+
- Laravel 12 or 13
- Livewire 3.5+ or 4 (pulled in automatically)

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

Authorization also flows through Laravel's **Gate**, so if you already grant access with a `Gate::before` rule (e.g. "admins can do anything") or prefer the gate idiom, just define a `viewVigilance` ability — exactly like Horizon's `viewHorizon` / Telescope's `viewTelescope`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewVigilance', fn ($user) => $user->isAdmin());
```

Schedule maintenance (in `routes/console.php` or your `Kernel`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vigilance:prune')->daily();
Schedule::command('vigilance:snapshot')->everyFiveMinutes();
Schedule::command('vigilance:schedule-sync')->hourly();
```

The dashboard is then at `/vigilance` (configurable).

> **Heads-up — `web` middleware:** the dashboard inherits `config('vigilance.middleware')`, which defaults to `['web']`. If your `web` group appends global redirects (locale prefixing like `/{locale}/…`, maintenance/teaser pages, forced auth), they will rewrite or 404 the dashboard URL — the same caveat Horizon, Pulse and Telescope carry. Either add `vigilance` to that middleware's skip-list, or set `vigilance.middleware` to a trimmed stack (e.g. `['web']` minus the redirect, or just `[\Illuminate\Session\Middleware\StartSession::class, …]`) so the dashboard isn't subject to app-wide request rewriting.

## How capture works

Vigilance injects a correlation id into each job's payload at dispatch (`Queue::createPayloadUsing`) and listens to the framework's queue events (`JobProcessing`, `JobProcessed`, `JobFailed`, `JobReleasedAfterException`). Because it reacts to runtime events and persists to its own tables, it is **completely driver-agnostic** — the same code tracks a job whether it ran on `sync`, `database`, `redis`, `sqs` or `beanstalkd`.

Artisan commands are captured via `CommandStarting` / `CommandFinished` (name, arguments, options, exit code, duration). The scheduler is tracked via `ScheduledTask*` events, which keep a per-task monitor up to date (last run, duration, lateness, failures).

## Manual control (dispatch jobs / run commands)

The dashboard can dispatch jobs and run artisan commands with user-supplied parameters. Because that is effectively remote code execution, it is **off by default** (like the read-only posture of Horizon / Telescope / Pulse). Opt in with `VIGILANCE_CONTROL_ENABLED=true`, then govern it with an **allowlist** (`config/vigilance.php` → `control`):

- **Jobs** — `mode` of `marker` (only jobs implementing `Vigilance\Contracts\Dispatchable`), `list` (explicit classes), `discover` (all `ShouldQueue` in `paths`), or `all`. The dispatch form is generated by reflecting the job's constructor (scalars, enums, dates and Eloquent models via `Model::findOrFail`). In `discover` mode, hide a job with side effects by implementing `Vigilance\Contracts\ShouldNotBeDispatchedManually`.
- **Commands** — `mode` of `list` (allow names/wildcards) or `all`. A `deny` list (destructive commands like `migrate:fresh`, `db:wipe`, `tinker`, …) always wins, as do Vigilance's own `vigilance:*` commands. `vigilance:doctor` reports any allowlisted command that was overridden this way, so a dropped entry is never silent.

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
- `notifications.mail` / `slack` / `discord` / `teams` / `webhooks` — where alerts are delivered
- `issues` — unified error tracking (sample rate, request-input capture, ignore list)
- `rum` — Real User Monitoring (enable, throttle, JS-error capture)
- `slos` — service-level objectives + error budgets (define your own)
- `logs` — trace-correlated log explorer (enable, min level, sample, retention)
- `alerts` — rule engine + incident tracking (per-rule thresholds, `incidents`)
- `release_health` — deploy-regression guard (comparison window, thresholds)
- `release` — current release identifier (tags issues + deploy markers)
- `ignore_paths` — exclude noisy endpoints (`/admin/*`, …) from all telemetry
- `rum.symbolicate` — symbolicate RUM JS errors against uploaded source maps

### Recommended production profile

```env
VIGILANCE_SAMPLE_RATE=0.1          # keep 10% of successes; 100% of failures
VIGILANCE_DB_CONNECTION=monitoring # optional dedicated connection
VIGILANCE_RETENTION_DAYS=7
```

### Isolating monitoring storage

By default Vigilance writes its `vigilance_*` tables to your application's default
database connection. Point `VIGILANCE_DB_CONNECTION` at a **dedicated connection**
to keep monitoring writes off your primary database — no bloat, independent
retention/pruning, and (most importantly) no write contention with your app.

This is especially worthwhile **if your app runs on SQLite**: SQLite locks at the
*file* level (one writer at a time), so a burst of telemetry writes sharing your
app's file can block the app itself (`database is locked`). A separate file removes
that — the app and the monitor never fight for the same lock:

```php
// config/database.php → 'connections'
'monitoring' => [
    'driver' => 'sqlite',
    'database' => database_path('vigilance.sqlite'),
    'journal_mode' => 'WAL',          // lets the dashboard read while telemetry writes
    'prefix' => '',
    'foreign_key_constraints' => false,
],
```

```env
VIGILANCE_DB_CONNECTION=monitoring
```

Then `php artisan migrate` creates every `vigilance_*` table in that file; all
reads, writes and pruning resolve to it automatically. Create the file first if it
doesn't exist (`touch database/vigilance.sqlite`).

Notes:

- **Enable WAL** (`journal_mode=WAL`) on the monitoring connection — without it the
  dashboard's reads serialize against telemetry writes within that file.
- Vigilance already buffers per request/job and flushes **batched** inserts after
  the response (not one row per event), so normal load is comfortable on SQLite.
- A single SQLite file is still **one writer at a time**, so at high telemetry
  volume the monitoring file itself becomes the serialization point. That's the
  ceiling where you move `VIGILANCE_DB_CONNECTION` to a server database
  (MySQL/PostgreSQL) or switch the APM ingest to the Redis write-behind driver
  (`VIGILANCE_APM_INGEST=redis`). Any connection driver works — the dedicated
  connection is not SQLite-specific.

## Alerting

Vigilance evaluates rule-based alerts at `vigilance:snapshot` time — queue
backlog, failure-rate, exception spikes, slow-request rate, overdue/failed
scheduled tasks (a **dead-man's-switch**), **SLO burn rate**, **new & regressed
issues**, **metric anomalies** (dynamic baselines) and **bad deploys** (release
regression) — each throttled per key. Alerts route to email, Slack, **Discord**, **Microsoft Teams** and any
number of **generic webhooks** (PagerDuty, Opsgenie, …) **straight from `.env`**
(no service provider required):

```env
VIGILANCE_ALERT_EMAILS=ops@example.com,cto@example.com   # single or comma-separated
VIGILANCE_SLACK_WEBHOOK=https://hooks.slack.com/services/…
VIGILANCE_DISCORD_WEBHOOK=https://discord.com/api/webhooks/…
VIGILANCE_TEAMS_WEBHOOK=https://outlook.office.com/webhook/…
VIGILANCE_ALERT_WEBHOOKS=https://events.pagerduty.com/…,https://…   # one or comma-separated
```

Fired alerts are persisted as **incidents** (opened on first fire, auto-resolved
when the alert stops recurring), tracked with occurrence counts and **MTTR** on
the **Incidents** page. You're notified **once when an incident opens** (and
again only if it escalates or recurs) — a sustained condition like a breaching
SLO won't email you every window. Set `alerts.renotify_minutes` for periodic
reminders while an incident stays open.

Prefer code? Set them in a service provider's `boot()` — an explicit call
**overrides** the `.env` values:

```php
use Vigilance\Vigilance;

Vigilance::routeMailNotificationsTo(['ops@example.com', 'cto@example.com']);
Vigilance::routeSlackNotificationsTo('https://hooks.slack.com/services/…');

// …or route alerts anywhere (PagerDuty, SMS, a custom Notification, …):
Vigilance::alertUsing(fn ($alert) => $team->notify(new QueueAlert($alert)));
```

If no mail recipient and no Slack webhook is configured, alerting stays silent
(nothing is sent). Tune the rules and thresholds under `notifications` /
`alerts` in `config/vigilance.php`.

## Commands

**Setup & maintenance**

| Command | Purpose |
|---|---|
| `vigilance:install` | Publish config, optionally migrate, print next steps (`--provider` also publishes the gate stub) |
| `vigilance:doctor` | Diagnose the install and surface common misconfigurations |
| `vigilance:prune` | Delete old runs (`--days`, `--failed-days`, `--dry-run`) and trim snapshots |
| `vigilance:snapshot` | Capture a throughput/runtime/wait-time metric snapshot |
| `vigilance:schedule-sync` | Sync defined scheduled tasks into monitors (`--keep-old`) |
| `vigilance:deploy` | Record a deployment marker (`--release`, `--commit`) — feeds release health |
| `vigilance:sourcemaps` | Upload JS source maps (`--release`, `--prune`) so RUM browser errors are symbolicated |

**Worker supervision** — the Horizon replacement (optional, works on any queue driver)

| Command | Purpose |
|---|---|
| `vigilance:supervise` | Run & auto-scale your queue workers (replaces `queue:work`). `--once` / `--max-time=N` for bounded/test runs |
| `vigilance:status` | Show running supervisors and their workers |
| `vigilance:pause` / `vigilance:continue` | Pause / resume all supervisors |
| `vigilance:restart` | Gracefully restart all workers (e.g. after a deploy) |
| `vigilance:terminate` | Gracefully stop the supervisor and all its workers |

**APM heartbeat & uptime**

| Command | Purpose |
|---|---|
| `vigilance:check` | Capture server stats + flush APM telemetry every second — the heartbeat. Runs as a daemon; `--once` for cron/testing |
| `vigilance:apm-work` | Drain buffered telemetry into storage for the `redis` write-behind ingest (`--once`) |
| `vigilance:health` | Ping the configured uptime URLs and record availability + latency |

For a production cutover from Horizon, run `vigilance:supervise` as your long-running worker process (under systemd / Supervisor / your platform's process manager) in place of `php artisan horizon`, and run `vigilance:check` as the APM heartbeat on each app server.

**Multi-node fleets.** Running the same supervisor on several servers is fully supported — each node keeps its own heartbeat and worker rows (keyed by node), and the Workers dashboard shows every node with its true total worker count. Each node is identified by its hostname; where that is random or shared (e.g. containers) set a stable `VIGILANCE_SUPERVISOR_HOST` per node so they're told apart.

## Schema & terminology

A few column/term names differ from the prose, worth knowing if you query the tables directly:

- `vigilance_runs.connection_name` holds the queue connection; `vigilance_supervisors.connection` is the same concept on the supervisor table.
- Failure grouping is stored as `vigilance_failure_groups.signature` (the "fingerprint") with an `occurrences` count.
- `vigilance_supervisors` uses a natural key on `name` (no surrogate `id`).

## AI-assisted development (Laravel Boost)

Vigilance ships first-class [Laravel Boost](https://laravel.com/docs/boost)
support. In any project that uses Boost, `php artisan boost:install` (and
`boost:update`) automatically loads Vigilance's **AI guidelines** and installs a
**`vigilance-development` agent skill** — so your coding agent (Claude Code,
Cursor, Copilot, …) already knows Vigilance's conventions: securing the dashboard
with `viewVigilance`, the `Dispatchable` / `ShouldNotBeMonitored` markers, the
driver-agnostic worker supervisor, `.env` alert routing, APM/tracing and more.
Nothing to wire up — the guidelines live in
`resources/boost/guidelines/core.blade.php` and the skill in
`resources/boost/skills/vigilance-development/`.

## Testing

```bash
composer install
./vendor/bin/pest
```

CI runs the suite against **SQLite, PostgreSQL 16 and MySQL/MariaDB 11.4** (the
storage layer's per-driver SQL — generated key-hash columns, upserts, LIKE
escaping — is exercised on each). Point the suite at a real engine locally with
`VIGILANCE_TEST_DB=pgsql` (or `mysql`) plus the usual `DB_*` env vars.

## License

MIT.
