# Changelog

All notable changes to `anousss007/vigilance` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
