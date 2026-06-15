# Changelog

All notable changes to `anousss007/vigilance` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2026-06-15

A proactive-monitoring release: release-health deploy gating, smarter alerting
(new/regressed issues, dynamic-baseline anomalies, bad-deploy detection),
readable RUM browser errors via source maps, and a single global ignore list.
See [docs/observability.md](docs/observability.md).

### Added
- **Release health & deploy-regression guard.** After each deployment marker,
  Vigilance compares request error-rate / latency / throughput in the window
  after the deploy against the equal window before it and assigns a health
  verdict (healthy / degraded / regressed), shown on a new **Releases** page
  (`/vigilance/releases`). A "regressed" verdict fires a critical
  `deploy_regression` alert — point a generic webhook at it to trigger an
  automatic rollback. Issues are now tagged with the release they were
  `first_release` seen in and `regressed_release` regressed in. Tune under
  `release_health`; set the current release via `vigilance.release` /
  `VIGILANCE_RELEASE` (falls back to `app.version`).
- **New-issue & regression alerting.** Alert the first time a new error
  signature appears (`new_issue` rule) and when a previously-resolved issue
  starts happening again (`issue_regression` rule, with a "regressed" badge in
  the inbox). Evaluated at snapshot time, so capture never fires alerts on the
  request/exception thread.
- **Dynamic-baseline anomaly detection** (`anomaly` rule). Z-scores each
  watched metric's latest bucket against its rolling baseline (request latency,
  5xx error rate and exceptions by default; configurable) and fires when it
  deviates — guarded against false positives so it doesn't alert on noise.
- **RUM source-map symbolication.** A pure-PHP Source Map v3 decoder plus a
  `vigilance:sourcemaps` command to upload maps per release. Minified browser
  error stacks captured by RUM are symbolicated at ingest, so the Issues inbox
  shows original source locations. Toggle with `rum.symbolicate`.
- **Global `ignore_paths`.** One config list (wildcards like `/admin/*` or
  `#regex#`) excludes a request path from ALL request-level telemetry at once —
  APM, tracing, RUM and web-request error capture — instead of per-recorder
  ignore lists.

## [0.4.1] - 2026-06-15

### Changed
- Updated the **Laravel Boost** integration (AI guidelines + the
  `vigilance-development` agent skill) to cover the v0.4.0 observability suite —
  Issues error tracking, per-route performance, RUM / Web Vitals, SLOs, custom
  business metrics and the trace-correlated log explorer — plus the expanded
  alerting channels (Discord / Teams / generic webhooks) and incident tracking,
  with `Vigilance::increment()` / `gauge()` and `@vigilanceRum` snippets. So
  coding agents generate correct code against the new features.

### Added
- `RELEASING.md` — a pre-release checklist (code, version strings, changelog,
  docs, the Boost integration, accessibility and the tag/release steps) so a
  release moves every surface forward together.

## [0.4.0] - 2026-06-15

A front-to-back **observability** release: error tracking, route & front-end
performance, SLOs, deeper alerting, custom metrics and a trace-correlated log
explorer — seven new dashboard areas, each built to the same production-first
posture as the rest of the package (captured cheaply, flushed after the response,
sampled and bounded). See [docs/observability.md](docs/observability.md).

### Added
- **Unified Issues error tracker.** Every reported exception — HTTP requests,
  `Vigilance::report()`, queued jobs, console commands and uncaught **browser**
  errors — is fingerprinted into a grouped **Issues** inbox (`/vigilance/issues`)
  with stacktrace, request/user context, a 7-day occurrence sparkline and a
  detail page. Per-group workflow: assign, prioritise, acknowledge, **mute** for
  a window, resolve / reopen, and bulk-retry failed jobs. New `source` dimension.
- **Per-route performance.** A new `Requests` APM recorder samples all requests
  and rolls them up per route on the **Routes** page (`/vigilance/routes`):
  throughput, error rate, Apdex and exact **p50 / p95 / p99** latency.
- **Real User Monitoring (RUM).** Core Web Vitals (LCP, INP, CLS, FCP, TTFB) and
  uncaught JS errors collected from real visitors via the `@vigilanceRum` beacon,
  with p75 ratings on the **Web Vitals** page (`/vigilance/vitals`). Public,
  throttled, strictly-validated ingest endpoint; off by default (`VIGILANCE_RUM`).
  Browser errors flow into the Issues inbox as source `browser`.
- **SLOs & error budgets.** Availability (`success_rate`) and latency (Apdex)
  objectives tracked against an error budget, with a short-window **burn-rate**
  alert, on the **SLOs** page (`/vigilance/slos`). Define them under `slos`.
- **Alerting depth & incidents.** Alerts now route to **Discord**, **Microsoft
  Teams** and any number of **generic webhooks** (PagerDuty, Opsgenie, …) on top
  of mail / Slack — all configurable from `.env`. Fired alerts are persisted as
  **incidents** (opened on first fire, auto-resolved when they stop recurring)
  with occurrence counts and **MTTR** on the **Incidents** page
  (`/vigilance/incidents`). New `slo_burn` alert rule.
- **Custom business metrics.** `Vigilance::increment()` / `Vigilance::gauge()`
  record any business KPI, auto-discovered onto the **Custom Metrics** page
  (`/vigilance/custom-metrics`) as counter & gauge cards with sparklines over a
  selectable window.
- **Trace-correlated log explorer.** Capture application log records into a
  searchable explorer (`/vigilance/logs`), correlated to the trace that emitted
  them — a trace's detail page lists the logs it produced and each log links back
  to its trace. Buffered and flushed after the response (zero request latency),
  context redacted by key; off by default (`VIGILANCE_LOGS`). New `vigilance_logs`
  table, trimmed by `vigilance:prune`.

### Changed
- Tracing now also records `redis`, `mail` and `notification` spans (on top of
  query / cache / HTTP).
- CI runs on `actions/checkout@v6`.
- New `docs/observability.md` guide; README and the docs site updated to cover the
  full suite. Every new dashboard page verified with axe-core — zero violations,
  desktop and mobile.

> **Schema note.** The new columns and tables (`vigilance_logs`,
> `vigilance_incidents`, and the `source` / `sample` / `context` / `muted_until`
> columns on `vigilance_failure_groups`) were folded into the base migration. If
> you ran a pre-0.4 dev build, run `php artisan migrate:fresh` to pick them up.

## [0.3.0] - 2026-06-15

### Added
- **Laravel Boost integration.** Vigilance now ships AI guidelines
  (`resources/boost/guidelines/core.blade.php`) and a `vigilance-development`
  agent skill (`resources/boost/skills/vigilance-development/SKILL.md`). When a
  project running [Laravel Boost](https://laravel.com/docs/boost) runs
  `boost:install` / `boost:update`, coding agents automatically learn
  Vigilance's conventions (dashboard authorization, the `Dispatchable` /
  `ShouldNotBeMonitored` markers, the worker supervisor, `.env` alert routing,
  APM/tracing) and generate correct code against the package.

## [0.2.0] - 2026-06-15

### Added
- Alert routing can now be configured **from `.env`** — set
  `VIGILANCE_ALERT_EMAILS` (single address or comma-separated list) and/or
  `VIGILANCE_SLACK_WEBHOOK` and Vigilance delivers alerts without a service
  provider. Maps to `notifications.mail` / `notifications.slack` in the config.
  An explicit `Vigilance::routeMailNotificationsTo()` /
  `routeSlackNotificationsTo()` call still takes precedence, and
  `routeMailNotificationsTo()` now also accepts a comma-separated string.

### Changed
- Dashboard accessibility hardening (WCAG 2.1 AA). Added a skip-to-content link
  and a focusable `<main>`, labelled the primary navigation with `aria-current`
  on the active item, gave the mobile-drawer / sidebar-collapse / theme toggles
  proper `aria-expanded` / `aria-pressed` / dynamic labels, marked the command
  palette as a labelled `role="dialog"` with an `aria-label`ed search field,
  removed a duplicate `<h1>` in the top bar, associated the Runs filter
  `<label>`s with their controls, hid decorative SVGs, and added a
  `prefers-reduced-motion` fallback. Darkened the **light-theme** semantic
  palette (status pills, primary button) so all text clears 4.5:1, and lifted
  the dark `--v-faint` token to do the same. Fixed a mobile horizontal-overflow
  on the overview from un-shrinkable truncated text. Verified with axe-core
  across all dashboard pages in both themes (zero violations).

## [0.1.3] - 2026-06-15

### Added
- Auto-scaling now works on **every supervisable queue driver**. `QueueDepth`
  reads live backlog for `beanstalkd` (stats-tube `current-jobs-ready`,
  pheanstalk v4–v8) and `sqs` (`ApproximateNumberOfMessages`) in addition to
  `database` (COUNT) and `redis` (LLEN) — so `vigilance:supervise` scales those
  connections by load instead of idling at `min_processes`. All four driver
  paths are unit-tested; the beanstalkd/sqs paths were additionally verified
  against the real pheanstalk and aws-sdk-php APIs. Depth reads are defensive
  (never throw) and fall back to "unknown" → min on any error.
- `suggest`: `pda/pheanstalk` and `aws/aws-sdk-php` (needed only to auto-scale a
  beanstalkd / SQS supervisor by backlog).

## [0.1.2] - 2026-06-14

### Fixed
- Worker termination on Windows is now fast and reliable. Previously a
  `vigilance:supervise` shutdown / scale-down / pause could block for the full
  worker `timeout` per worker (waiting on a SIGTERM Windows cannot deliver) and,
  under heavy scale churn, leave orphaned `queue:work` processes behind. Workers
  now launch with a `#vigilance`-tagged `--name`, and the supervisor force-reaps
  its own orphaned workers on terminate / pause — also cleaning up workers left
  by a crashed master. POSIX behaviour is unchanged (graceful SIGTERM, then
  SIGKILL; relies on the OS/systemd for tree reaping). Validated by a real
  multi-process chaos battery (drain, autoscale, crash-recovery, rolling
  restart, failure capture, balancing, chaos) with zero orphans.

### Added
- `Supervisor::workerPids()` and `Supervisor::poolCounts()` for introspecting a
  running supervisor's worker processes.

## [0.1.1] - 2026-06-14

### Fixed
- The dashboard stylesheet is now cache-busted by a hash of its **contents**
  rather than the package version, so the long-lived `immutable` cache header no
  longer serves a stale stylesheet after an upgrade. (Symptom: the redesigned
  dashboard rendering unstyled because the browser kept the old CSS.)
- `vigilance:doctor` now recognises a `viewVigilance` Gate ability (or a
  `Gate::before` rule) as configured dashboard authorization, instead of always
  reporting the local-only default. Authorization already flowed through the
  Gate — this fixes the false diagnostic.
- `vigilance:doctor` no longer advises pointing the supervisor at a
  non-drainable queue driver (`sync`/`null`, or push-only "run after response"
  drivers like `background`); it reports those as not supervisable instead.

### Changed
- Dashboard authorization no longer registers a package-level `viewVigilance`
  gate; `Vigilance::check()` resolves a `viewVigilance` ability / `Gate::before`
  rule and falls back to local-only itself. Behaviour is unchanged, but an
  app-defined `viewVigilance` ability is now detectable.

## [0.1.0] - 2026-06-14

### Changed
- Complete dashboard UI/UX redesign. A grouped, collapsible left sidebar (with a
  ⌘K command palette and a mobile drawer) replaces the single-row top navigation;
  a new emerald "technical dark" design system with semantic light/dark tokens, a
  sans-for-UI / monospace-for-data type system, and a consistent component kit
  (cards, stat tiles, tables, status pills, forms, empty states) is applied across
  every page and APM card. Presentational only — no behaviour, route or data
  changes.

## [0.0.1] - 2026-06-14

First public release.

### Added
- Driver-agnostic capture of queue jobs (full `queued → running → done/failed`
  lifecycle), artisan commands, and scheduled tasks.
- Standalone Livewire dashboard: overview, runs, run detail, failures,
  dispatcher, command runner, schedule and workload pages. Compatible with both
  Livewire 3.5+ and Livewire 4 (class-based components; no rewrite required).
- Supports Laravel 12 and 13 on PHP 8.2+ (Symfony 7 / 8). Laravel 11 is not
  supported — it is past Laravel's security-support window.
- Manual control: dispatch allowlisted jobs (typed form reflected from the
  constructor) and run allowlisted artisan commands, with an audit log.
- Failure grouping with Sentry-style fingerprints and a `FailureRecorded` event
  for alerting.
- Metrics snapshots (throughput / runtime / wait-time) and per-driver queue
  depth.
- Production safety: dispatch-time sampling (sampled-out successes cost zero
  writes; failures always captured), size caps, secret redaction, a dedicated
  storage connection, and an anti-crash capture guard.
- Console: `vigilance:install`, `vigilance:doctor`, `vigilance:prune`,
  `vigilance:snapshot`, `vigilance:schedule-sync`.
- `php artisan about` integration and Octane state-reset hooks.
- **Worker supervision** (Horizon-parity, driver-agnostic): `vigilance:supervise`
  runs and auto-scales worker pools on any queue driver, with `pause` /
  `continue` / `restart` / `terminate` / `status`, `auto`/`simple`/off balancing,
  time- and size-based auto-scaling, `balance_cooldown`, `nice`, and a workers
  dashboard. Works on Windows (control-plane via cache flags, not just signals).
- **Whole-app APM** (Pulse-parity, driver-agnostic): time-bucketed aggregates for
  servers (CPU/memory/disk), slow requests / queries / outgoing HTTP, cache
  hit-rate, exceptions, per-user usage (requests + jobs), queue throughput, slow
  jobs, mail, notifications and logs — with per-recorder sampling, thresholds,
  ignores and groups. `vigilance:check` heartbeat. Customizable, publishable,
  lazily-loaded card dashboard. Optional Redis write-behind ingest
  (`vigilance:apm-work`).
- **Tracing**: per-request / per-job waterfalls (query / cache / HTTP / redis /
  mail / notification spans), tail-sampling (keep slow / errored / sampled),
  N+1 detection, exception→trace linking. Off by default.
- **Job batches** dashboard (progress / cancel / retry-failed).
- **Metrics** drill-down pages (throughput + runtime per job class and queue).
- **Deployment markers** (`vigilance:deploy`) overlaid on the throughput chart.
- **Rule-based alerting**: queue backlog, failure rate, exception spikes, slow
  request rate and overdue/failed scheduled tasks, routed to mail / Slack / a
  custom sink.
- **Uptime monitoring** (`vigilance:health`) — availability + latency per URL.
- **Issue workflow** on failure groups (status / priority / assignee).
- A clean `Ingest` export seam for forwarding telemetry to an external APM.
