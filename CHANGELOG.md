# Changelog

All notable changes to `anousss007/vigilance` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.7] - 2026-06-16

### Documentation
- **Isolating monitoring storage.** Documented how to keep Vigilance's writes off
  your primary database with a dedicated `VIGILANCE_DB_CONNECTION`, and in
  particular how to give Vigilance its **own SQLite file** so a telemetry burst
  can't lock the app's database (`database is locked`). Covers enabling WAL on the
  monitoring connection, the fact that Vigilance already buffers and flushes
  batched inserts after the response, and the single-writer-per-file ceiling
  (where to move to MySQL/PostgreSQL or the Redis write-behind APM ingest). Added
  to the README, the inline `config/vigilance.php` storage section, and the Laravel
  Boost guidelines/skill. No code change — the capability already existed.

## [0.5.6] - 2026-06-16

Multi-node fix from a distributed-deployment attack pass, plus an adversarial
audit of the RUM symbolicator.

### Fixed
- **Multi-node fleets under-reported their workers / supervisors clobbered each
  other.** Supervisor and worker heartbeat rows were keyed by supervisor *name*
  only (the `vigilance_supervisors` table even made `name` its primary key). When
  the same supervisor config ran on more than one server — the normal way to
  scale workers horizontally — each node's heartbeat overwrote the others' row
  and each node's worker-set write *deleted the other nodes' worker rows*. The
  dashboard then showed a single flapping node and a worker count far below the
  real fleet (e.g. 5 shown for an 8-worker, 2-node fleet). State is now keyed by
  **(name, host)**: every node keeps its own supervisor + worker rows, the
  dashboard shows each node (with its hostname) and the true fleet totals, and
  pruning/`forget` act per-node so a dead node never removes a live one's rows.
  A configurable `supervision.host` (env `VIGILANCE_SUPERVISOR_HOST`, default the
  machine hostname) identifies each node — set it where the hostname is random or
  shared (e.g. containers).

  **Schema note:** the base migration changed (`vigilance_supervisors` gains an
  `id` primary key + a `unique(name, host)`; `vigilance_workers` is now
  `unique(supervisor, host, pid)`). Existing installs must run
  `php artisan migrate:fresh` (supervisor/worker rows are ephemeral heartbeats,
  so nothing of value is lost).

### Validated (no code change)
- **RUM symbolicator hardening**: the public RUM stack-trace symbolicator was
  attacked with 200 KB pathological stacks (no-match lazy-regex worst case → 0.1 ms,
  no ReDoS), malformed source maps (invalid JSON, bad VLQ) and high token counts —
  it stays fast and degrades to "unsymbolicated" without crashing. Stacks are
  capped (8 KB) and errors per request bounded (≤5) before symbolication, on top
  of the endpoint's rate limit.

## [0.5.5] - 2026-06-16

A second, harder adversarial pass — DDoS/flood amplification, a cardinality bomb,
a failing-job storm, a job-dispatching endpoint under flood, multi-driver
supervision and the public RUM endpoint — which surfaced and fixed one real
concurrency bug.

### Fixed
- **Failure-group occurrence counts undercounted under concurrent failures.**
  The per-group `occurrences` counter was a read-modify-write
  (`$group->occurrences = $group->occurrences + 1; $group->save()`), so when
  several workers recorded the same failure signature at once — exactly what a
  failing-job storm produces — increments clobbered each other and the count
  drifted low (measured ~10% loss across 2000 failures on 3 workers). It now uses
  a race-safe `createOrFirst()` (leaning on the unique `signature` index, so no
  duplicate groups) plus an **atomic SQL increment** (`occurrences = occurrences
  + 1`), so the count is exact under any concurrency. Failure *grouping* itself
  was already bounded — 2000 distinct failures still collapse to one group.

### Validated (no code change)
- **No DDoS amplification.** Under sustained flood, throughput and error rate are
  statistically identical with Vigilance on or off (the after-response flush adds
  no user-facing latency); enabling it never introduced an error.
- **Cardinality is bounded.** A flood of thousands of distinct URLs collapses to
  the route pattern in APM (1 key, not thousands), and a flood of random
  non-existent URLs (the common DDoS shape) writes **nothing** — only matched
  routes are recorded. The APM aggregate tables cannot be made to explode.
- **Job-dispatch storm**: an endpoint enqueuing 10 jobs/request under flood
  captured every job exactly (queued-row parity) with no failed requests;
  `VIGILANCE_SAMPLE_RATE` throttles the enqueue-write load proportionally.
- **Multiple queue drivers supervised at once**: a single `vigilance:supervise`
  process drained database + Redis + beanstalkd concurrently, each captured with
  the correct connection.
- **Public RUM ingest endpoint** is safe to expose: rate-limited
  (`rum.throttle`, default 120/min), and each request is capped to ≤12 validated
  metrics + ≤5 errors with length-bounded fields — it cannot be used to bloat
  storage.
- **Extreme concurrency** (hundreds of concurrent connections): the app degrades
  gracefully and recovers immediately, with no Vigilance-induced errors and
  connection use bounded to ~one storage connection per worker.

## [0.5.4] - 2026-06-16

A relentless prod-scenario validation pass on real Linux infrastructure — every
common web server and app runtime, all four supervisable queue drivers,
server-class databases, storage-outage chaos and high concurrency — which
surfaced and fixed four real issues.

### Fixed
- **Long-running daemons were captured as perpetually-"running" command runs.**
  `octane:start`, `reverb:start`, `pulse:work` and `pulse:check` were not in the
  default command-ignore list, so running Vigilance under Octane (or alongside
  Reverb/Pulse) recorded the daemon itself as a command run that never finishes —
  and was left dangling in `running` forever every time the process was signalled
  (deploy, restart, OOM). They are now excluded. The exclusion is also enforced as
  an unconditional code-level baseline (`Defaults::daemonCommands()`), so it
  protects installs whose published config predates this list — not only fresh
  publishes. Mirrors how `queue:work`/`schedule:work`/`horizon` were already
  handled.
- **Redis jobs were recorded under a different queue name than other drivers.**
  Laravel's Redis queue reports the queue as its storage key (`queues:default`)
  rather than the logical name (`default`) used by the database/beanstalkd
  drivers — and by the supervisor, the queue-depth probe and the supervisor
  config. The recorder now normalizes it, so per-queue grouping is consistent
  across drivers and Redis runs correlate to their configured supervisor/queue.
- **Batched jobs were not linked to their batch.** The `batch_id` column on runs
  was never populated, so a batch could not be drilled into its individual job
  runs. Batchable jobs now record their `batchId` (matching Laravel's
  `job_batches`), while non-batch jobs stay null.
- **Workers orphaned by a hard-killed supervisor are now reaped on the next
  boot.** When the `vigilance:supervise` master is killed without a chance to
  clean up — a `SIGKILL`, an OOM kill, or a restart under a process manager that
  does not tear down the worker group (e.g. Supervisor/supervisord, unlike
  systemd's cgroup teardown) — its `queue:work` children were left running, so a
  crashed-then-restarted master piled fresh workers on top of the old ones
  (over-provisioning, double-draining, stale code/config). The supervisor now
  sweeps any worker carrying its own `#vigilance` name marker before launching
  its pools. POSIX support completes the cross-platform reap the marker was
  always intended for (previously Windows-only).

### Validated (no code change)
- **Web servers**: Nginx + PHP-FPM, Apache (mod_proxy_fcgi) + PHP-FPM, and
  Caddy + PHP-FPM — request/trace/APM capture works under each, and the
  after-response flush fires under FPM's `fastcgi_finish_request`.
- **Laravel Octane on every server** — FrankenPHP, Swoole 6.2, OpenSwoole 26.2
  and RoadRunner 2025.1 — each under 800 requests at concurrency 16 with 0 failed
  and a constant per-request span count (the `RequestReceived` state-reset hook
  isolates each request — no cross-request telemetry leakage on persistent
  workers).
- **All four supervisable queue drivers**: database, Redis (phpredis),
  beanstalkd (1.13 + pheanstalk v8) and the auto-scaling supervisor draining each
  — including the cross-driver supervisor claim on beanstalkd, which cannot be
  tested on Windows.
- **Running under Supervisor (supervisord)** and with OPcache + `config:cache` /
  `route:cache` / `event:cache` — capture and the dashboard work under fully
  cached, optimized production config.
- **Never breaks the app when its storage is down.** With Vigilance's storage on
  a separate connection, taking that database down mid-traffic left the
  application serving 100% of requests and draining its queue; capture resumed
  automatically when storage returned, with no stuck or corrupt rows.
- **Job lifecycles**: retries (`tries`), timeouts (captured as failures), batches
  and chains all captured correctly.
- **Concurrency**: 1200 requests at concurrency 24 against MySQL storage — no
  lost writes and the incremental aggregate counts summed exactly, with no
  deadlocks.
- **Dashboard at scale**: every page renders (HTTP 200, sub-300 ms) against
  60k runs / 100k APM entries / 22k traces.
- **Fresh install** on a clean Laravel 12 app: `vigilance:install`, `migrate`,
  `vigilance:doctor` (green) and the dashboard all work.
- **Full suite green on real server-class databases**: PostgreSQL 18.4 and
  MySQL 8.4 (the CI matrix uses PostgreSQL 16 + MariaDB 11.4).

### Known limitations
- A job whose worker is hard-killed mid-execution (SIGKILL / OOM / cgroup
  teardown) leaves its in-flight run in `running` status, since no
  completion/failure event fires. Rare, and the job itself is retried by the
  queue as normal; a future reconciliation pass will reconcile such rows.

## [0.5.3] - 2026-06-16

Cross-database hardening — the full suite now runs against SQLite, PostgreSQL
and MySQL/MariaDB (previously SQLite-only in CI), which surfaced and fixed real
bugs the other engines hit.

### Fixed
- **MySQL / MariaDB install was broken (critical).** The `vigilance_aggregates`
  unique index auto-named to 65 characters — over MySQL/MariaDB's 64-char
  identifier limit (error 1059) — so migrations failed and the package could not
  be installed on MySQL/MariaDB at all. Named it (and the 4-column index)
  explicitly. PostgreSQL truncated silently; SQLite has no limit; which is why
  the SQLite-only CI never caught it.
- **PostgreSQL: float into bigint.** `wait_ms` / `duration_ms` wrote Carbon-3
  float millisecond values into `bigint` columns, which PostgreSQL rejects
  (MySQL/SQLite silently truncate). Now cast to int.
- **Cross-driver LIKE filters.** The silenced-jobs filter and name/message
  searches used `LIKE` with class names whose backslashes are escape characters
  on PostgreSQL and MySQL (not SQLite), so they silently failed there. New `Like`
  helper builds patterns with an explicit `ESCAPE` clause.
- **Queue-depth probe.** A missing `jobs` table threw, and on PostgreSQL a thrown
  query inside a transaction aborts the whole transaction (defeating the
  never-break-the-app guard). It now checks the table exists first.

### Changed
- CI runs the suite against **PostgreSQL 16** and **MariaDB 11.4** services in
  addition to SQLite. The test suite is connection-configurable via
  `VIGILANCE_TEST_DB`. Validated green on all three engines (234 tests each).

## [0.5.2] - 2026-06-15

### Changed
- The Laravel Boost **AI guidelines** now document the once-per-incident
  alerting behaviour (matching the skill, README and config) — coding agents
  learn that a sustained condition alerts once and the rest lives on the
  dashboard. No code change.

## [0.5.1] - 2026-06-15

### Fixed
- Alerts for a **sustained** condition no longer repeat every throttle window
  (e.g. a breaching SLO emailing every 15 minutes — bad DX). With incident
  tracking on (the default), you're notified **once when an incident opens**,
  and again only if its severity escalates, or it resolves and later recurs. Set
  `alerts.renotify_minutes` (`VIGILANCE_ALERT_RENOTIFY_MINUTES`) for periodic
  reminders while an incident stays open (0 = once). With incidents off,
  behaviour is unchanged (one notification per `throttle_minutes`).

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
