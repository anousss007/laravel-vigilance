# Changelog

All notable changes to `anousss007/vigilance` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
