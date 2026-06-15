---
name: vigilance-development
description: Build and operate the Vigilance (anousss007/vigilance) control center in a Laravel app — dashboard authorization, automatic job/command/scheduler capture and exclusions, manual-dispatch allowlists, the driver-agnostic worker supervisor that replaces Horizon, APM, request/job tracing, the observability suite (Issues error tracking, per-route performance, RUM/Web Vitals, SLOs, custom metrics, trace-correlated logs), and rule-based alerting with incidents (mail/Slack/Discord/Teams/webhooks/custom). Use when installing, configuring, securing, or extending Vigilance.
---

# Vigilance Development

Vigilance is a self-contained Livewire control center for Laravel queues, jobs, commands and the scheduler. It is **driver-agnostic** (database, redis, sqs, beanstalkd, sync) and **production-first** (sampling, size caps, secret redaction, a crash-proof capture guard). The dashboard is at `/vigilance`. All config lives in `config/vigilance.php` and is documented inline.

## When to use this skill

Use it when you are: installing/configuring Vigilance, securing the dashboard, deciding what gets captured, enabling manual job/command dispatch, replacing Horizon/`queue:work` with the supervisor, wiring up APM or tracing, enabling the observability layers (error tracking, route/front-end performance, RUM, SLOs, custom metrics, the log explorer), or adding alerting (including custom rules, extra channels and incident tracking).

## Install & authorize

```bash
composer require anousss007/vigilance
php artisan vigilance:install   # publishes config + prints next steps
php artisan migrate             # migrations are auto-loaded
php artisan vigilance:doctor    # diagnose common misconfigurations
```

The dashboard is **local-only until you authorize it** (secure by default). Use a Gate (preferred — same idiom as Horizon's `viewHorizon`) or a closure, in a service provider's `boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewVigilance', fn ($user) => $user->isAdmin());
// or: \Vigilance\Vigilance::auth(fn ($request) => in_array($request->user()?->email, ['you@example.com']));
```

> **`web` middleware caveat:** the dashboard inherits `config('vigilance.middleware')` (default `['web']`). If your `web` group appends global redirects (locale prefixing, maintenance/teaser pages, forced auth) they will rewrite or 404 the dashboard URL — the same caveat Horizon/Pulse/Telescope carry. Skip the `vigilance` path in those middleware, or trim `vigilance.middleware`.

## What is captured — and how to exclude

Capture is automatic via framework events. **Never** add manual tracking. To reduce noise, prefer exclusions over disabling:

- Mark a job/command class with `Vigilance\Contracts\ShouldNotBeMonitored`.
- Or list patterns in `except.jobs` / `except.commands` in config.
- Tune volume with `capture.sample_rate` (fraction of **successful** runs kept; failures are always captured) and keep monitoring writes off your primary DB with `storage.connection`.

```php
// Run a block without Vigilance observing it (e.g. its own writes):
\Vigilance\Vigilance::withoutRecording(fn () => $this->doInternalWork());

// Surface a caught/swallowed exception to the APM exception card + active trace:
try { /* ... */ } catch (\Throwable $e) { \Vigilance\Vigilance::report($e); }
```

## Manual control: dispatch jobs & run commands (treat as RCE)

Off by default. Enable deliberately and govern with allowlists. Every manual action is written to an audit log.

```php
// config/vigilance.php
'control' => [
    'enabled'  => env('VIGILANCE_CONTROL_ENABLED', false),
    'jobs'     => ['mode' => 'marker'], // marker | list | discover | all
    'commands' => [
        'mode'  => 'list',              // list | all
        'allow' => ['mail:*', 'cache:clear'],
        'deny'  => ['migrate:fresh', 'db:wipe', 'tinker'], // deny always wins
    ],
],
```

Opt a job in (when `jobs.mode` is `marker`) and give it a friendly label; the constructor is reflected to build the form:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Vigilance\Contracts\Dispatchable;

class ProcessPodcast implements ShouldQueue, Dispatchable
{
    public static string $vigilanceLabel = 'Process a podcast';
    public static string $vigilanceDescription = 'Transcode and publish a podcast episode';

    public function __construct(public Podcast $podcast, public bool $notify = true) {}
}
```

In `discover` mode, hide a job with side effects using `Vigilance\Contracts\ShouldNotBeDispatchedManually`. Vigilance's own `vigilance:*` commands are always blocked.

## Worker supervision (the Horizon replacement)

Run `php artisan vigilance:supervise` as your long-running worker process (under systemd / Supervisor / your platform manager) **in place of** `php artisan horizon` or `queue:work`. It works on every supervisable driver and auto-scales by backlog. Define supervisors per environment:

```php
// config/vigilance.php
'environments' => [
    'production' => [
        'payments' => [
            'connection'    => 'redis',
            'queue'         => ['payments'],
            'balance'       => 'auto',   // auto | simple | false
            'min_processes' => 2,
            'max_processes' => 10,
            'max_time'      => 3600,
        ],
    ],
],
```

Control it with `vigilance:status`, `vigilance:pause` / `vigilance:continue`, `vigilance:restart` (e.g. after a deploy), `vigilance:terminate`. It is Windows-safe (cache control-plane + tagged worker reaping). Do not point it at the same queues Horizon is already draining.

## APM & tracing

- Run `php artisan vigilance:check` as a per-server heartbeat (captures server stats and flushes telemetry; recorders capture cheaply and flush **after** the response — zero request latency).
- Per-recorder `sample_rate` and `threshold` (ms) bound overhead/storage; configure under `apm` in config.
- The `Requests` recorder rolls **all** requests up per route for the **Routes** page (throughput, error rate, Apdex, p50/p95/p99) — distinct from `SlowRequests` (threshold-only). Apdex target: `VIGILANCE_APM_APDEX_MS`.
- Optional Redis write-behind ingest: drain with `php artisan vigilance:apm-work`.
- Tracing is off by default. Enable with `VIGILANCE_TRACING=true`; it tail-samples (keeps only slow / errored / sampled traces). Spans cover query / cache / HTTP / redis / mail / notification.
- Uptime checks: `php artisan vigilance:health` records availability + latency for configured URLs.

## Observability suite

Front-to-back observability, each layer on its own dashboard page. Full reference: `docs/observability.md`.

- **Issues — error tracking** (`/vigilance/issues`, on by default). Every exception — HTTP requests, `Vigilance::report()`, queued jobs, console commands and uncaught **browser** errors — is fingerprinted into a grouped inbox with stacktrace + context. Tune with `issues.sample_rate` / `issues.except`; `issues.capture_request_input` is off for privacy.
- **Per-route performance** (`/vigilance/routes`, on). See APM above.
- **RUM / Web Vitals** (`/vigilance/vitals`, **off** by default). Set `VIGILANCE_RUM=true` and add the `@vigilanceRum` Blade directive to your layout `<head>`. Collects LCP/INP/CLS/FCP/TTFB + JS errors (which land in Issues as source `browser`) via a public, throttled, validated beacon.
- **SLOs & error budgets** (`/vigilance/slos`, off until defined). Add objectives under `slos`; `sli` is `success_rate` or `latency` (Apdex). A `slo_burn` alert fires on a high short-window burn rate.
- **Custom business metrics** (`/vigilance/custom-metrics`, on). Record KPIs anywhere — they auto-appear:

```php
use Vigilance\Vigilance;

Vigilance::increment('signups');                 // counter (sum + count)
Vigilance::gauge('cart_value', $cart->total());  // gauge (avg / peak / min)
```

- **Log explorer** (`/vigilance/logs`, **off** by default). Set `VIGILANCE_LOGS=true` to capture `Log::*` records into a searchable explorer **correlated to the trace that emitted them** (the trace detail page lists its logs, and each log links back). Set the minimum level with `VIGILANCE_LOGS_LEVEL` (default `debug`; raise to `warning`/`error` at volume). Buffered + flushed after the response; trimmed by `vigilance:prune`.

## Alerting (mail / Slack / Discord / Teams / webhooks / custom) + incidents

Rules are evaluated at `vigilance:snapshot` time and throttled per key — queue backlog, failure rate, exception spikes, slow-request rate, overdue/failed scheduled tasks (a **dead-man's-switch**) and **SLO burn rate**. Configure built-in rules under `alerts.rules`; route delivery from `.env` or code. Fired alerts are persisted as **incidents** (`alerts.incidents`, on by default) — opened on first fire, auto-resolved when they stop recurring — with occurrence counts and MTTR on the **Incidents** page (`/vigilance/incidents`).

```ini
# .env — simplest path, no provider needed
VIGILANCE_ALERT_EMAILS=ops@example.com,cto@example.com   # single or comma-separated
VIGILANCE_SLACK_WEBHOOK=https://hooks.slack.com/services/...
VIGILANCE_DISCORD_WEBHOOK=https://discord.com/api/webhooks/...
VIGILANCE_TEAMS_WEBHOOK=https://outlook.office.com/webhook/...
VIGILANCE_ALERT_WEBHOOKS=https://events.pagerduty.com/...   # one or comma-separated
```

```php
// ...or in a service provider's boot() (overrides the .env values)
use Vigilance\Vigilance;

Vigilance::routeMailNotificationsTo(['ops@example.com', 'cto@example.com']);
Vigilance::routeSlackNotificationsTo('https://hooks.slack.com/services/...');
Vigilance::routeDiscordNotificationsTo('https://discord.com/api/webhooks/...');
Vigilance::routeTeamsNotificationsTo('https://outlook.office.com/webhook/...');
Vigilance::routeWebhooksTo(['https://events.pagerduty.com/...']);

// Custom channel (SMS, a Notification, …):
Vigilance::alertUsing(fn (\Vigilance\Notifications\Alert $alert) =>
    Notification::route('slack', $url)->notify(new QueueAlert($alert))
);
```

Add your own rule by implementing `AlertRule` and registering it under `alerts.custom`:

```php
namespace App\Vigilance\Alerts;

use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

class TooManyRefunds implements AlertRule
{
    public function evaluate(): iterable
    {
        $count = Refund::whereBetween('created_at', [now()->subHour(), now()])->count();

        if ($count > 50) {
            // Alert(key, title, message, level = info|warning|critical)
            yield new Alert('refund-spike', 'Refund spike', "{$count} refunds in the last hour.", 'critical');
        }
    }
}
```

```php
// config/vigilance.php
'alerts' => [
    'custom' => [
        \App\Vigilance\Alerts\TooManyRefunds::class,
    ],
],
```

## Forwarding telemetry (the Ingest seam)

To fan APM telemetry out to an external system, bind a custom implementation of `Vigilance\Apm\Contracts\Ingest`:

```php
interface Ingest
{
    public function ingest(\Illuminate\Support\Collection $items): void; // Collection<Entry|Value>
    public function digest(\Vigilance\Apm\Storage $storage): int;        // drain buffered items
    public function trim(): void;
}
```

## Commands reference

| Command | Purpose |
| --- | --- |
| `vigilance:install` | Publish config, optionally migrate, print next steps |
| `vigilance:doctor` | Diagnose the install and surface misconfigurations |
| `vigilance:prune` | Delete old runs (`--days`, `--failed-days`, `--dry-run`) + trim snapshots |
| `vigilance:snapshot` | Capture a metric snapshot **and evaluate alerts** |
| `vigilance:schedule-sync` | Sync defined scheduled tasks into monitors |
| `vigilance:deploy` | Record a deployment marker on the timeline |
| `vigilance:supervise` | Run & auto-scale workers (replaces `queue:work`); `--once` / `--max-time=N` |
| `vigilance:status` / `pause` / `continue` / `restart` / `terminate` | Supervisor control |
| `vigilance:check` | APM heartbeat (daemon; `--once` for cron/testing) |
| `vigilance:apm-work` | Drain Redis write-behind telemetry |
| `vigilance:health` | Ping uptime URLs; record availability + latency |

## Gotchas

- It's **secure by default** — if the dashboard 404s/403s, you haven't defined `viewVigilance` / `Vigilance::auth()`, or `web` middleware is rewriting the path.
- Failures are always captured regardless of `sample_rate`; only successes are sampled out.
- The supervisor only drains *supervisable* drivers (not `sync` / `null` / push-only "after response" drivers).
- Don't run the supervisor and Horizon on the same queues.
- **RUM and the log explorer are off by default** — they need `VIGILANCE_RUM=true` (+ the `@vigilanceRum` directive in your layout) and `VIGILANCE_LOGS=true` respectively. SLOs show nothing until you define one under `slos`.
- Log–trace correlation only happens when **both** logs and tracing are enabled (a log's `trace_id` is the in-flight trace).
- If you published `config/vigilance.php`, re-publish (or merge new keys — `issues`, `rum`, `slos`, `logs`, `alerts.incidents`, `notifications.discord` / `teams` / `webhooks`) after upgrading so `.env` settings resolve.
