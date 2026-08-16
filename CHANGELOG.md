# Changelog

All notable changes to `anousss007/vigilance` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.9.3] - 2026-08-16

### Fixed
- **The Usage page's "Pruning is behind" check fired on healthy installs, and
  blamed the scheduler for it.** Two independent faults, both reported from a
  production PostgreSQL install:
  - It read `vigilance.retention_days`, a key the package does not ship, so it
    silently measured every install against the 7-day fallback instead of the
    configured `retention.days` (default 14) that `vigilance:prune` actually
    deletes at. An install keeping 14 days was told 2,799 rows were past a
    window it had never set.
  - It counted any row past its window as a breach, ignoring that retention is
    enforced periodically. Traces are kept 72h and the package's own install
    output and README tell you to prune **daily**, so every install that
    followed the instructions showed the warning permanently. The check now
    tolerates one prune interval of overhang, read from the synced schedule so
    it matches the cadence you actually run (falling back to a day).

  The banner also no longer asserts "the scheduled prune is not running" — the
  one conclusion the check cannot draw, and the sentence that sent the reporter
  diagnosing a failure that did not exist. It now states what it measured.
- **Four more configuration keys were read but never shipped.**
  `apm.storage.chunk` is now in the config file where it can be discovered; the
  three `alerts.rules.*` overrides that intentionally mean "inherit" are
  documented as such. A new test asserts every literal `config('vigilance.…')`
  key in the package resolves against the shipped array, so the next typo fails
  at authoring time instead of silently reading a default forever.
- The MCP `footprint` tool hands the same breach list to an agent, so it carries
  the grace too — an agent can no longer report a broken scheduler off a healthy
  install.

Reported against 0.9.2 on PostgreSQL, with the diagnosis and the reproduction
that made it actionable.

## [0.9.2] - 2026-08-15

A dashboard-appearance release. Vigilance shipped a UI that nothing had ever
looked at: the whole test suite was green while every table on every page
rendered with browser-default styling. This fixes that, and adds the gate that
would have caught it — `composer visual` now screenshots and audits all 26 pages
in both themes at desktop and phone width, and a release cannot be tagged
without it.

### Fixed
- **Every table in the dashboard rendered unstyled.** The pages write plain
  `<th>`/`<td>` inside `<x-vigilance::ui.table>`, and the rules that style those
  cells hang off a `.v-table` class the component never emitted — so twenty
  views rendered with browser-default tables: no cell padding, no row borders,
  bold centred headers, and numbers colliding with the text beside them
  (`3il y a 5 jours`). The component now carries that class.
- **A wide table's off-screen columns looked like missing ones.** Overlay
  scrollbars are invisible until touched, so on the Issues inbox the entire
  action column simply appeared to be absent. Table containers now show a thin
  permanent scrollbar and an edge shadow that fades out at the end of the
  scroll, and on Issues, Incidents and Tags the action column stays pinned to
  the right edge on desktop (never on a phone, where it would be wider than the
  viewport and cover the table).
- **Multi-series charts drew every series in the same colour.** The chart
  palette was five steps of one emerald ramp — a sequential ramp doing a
  categorical job — so the fleet chart's supervisors were indistinguishable and
  its darkest steps were nearly invisible on the dark surface. Replaced with
  five distinct hues, validated for colour-vision deficiency and contrast
  against both surfaces, and applied in fixed order so a series keeps its colour
  when the number of series changes.
- **Rolling back on SQLite failed.** The migrations that add an indexed column
  to `vigilance_failure_groups` dropped the column without dropping its index
  first, which aborts `migrate:refresh`/`migrate:rollback` on SQLite.
- **Six status badges had their closing tags in the wrong place**, putting the
  label outside the badge it belonged to (Pending, Workload, Overview).
- **"Cancel selected" spanned the full width of its card** on the Pending page:
  the button was not marked as a card action, so it fell onto its own header row.
- **Long identifiers pushed pages sideways on a phone.** A fully-qualified job
  class as a page title has nothing to break on; Run detail, Trace detail, the
  Workers cards and the APM server cards now wrap and shrink instead.
- **An issue with no errors in seven days drew a flat sparkline** pinned to the
  baseline, which read as a stray underscore rather than as "nothing happened".
  It shows a dash, like the no-data case it is.
- **Hiding one series on the fleet chart repainted the others.** The lines were
  coloured by their rank among the *visible* series while the legend coloured by
  rank among all of them, so toggling a pool off left the legend disagreeing
  with the chart. Colour now follows the series itself. Past five pools the tail
  is summed into one neutral "Other" series instead of cycling the palette,
  which used to draw two different pools identically.
- **The reflected forms on Dispatch and Commands had unlabelled inputs** — the
  parameter name sat in a `<span>` beside the control, so a screen reader
  reached the field with nothing to announce (axe-core: `label`, critical).
- **The time-range picker's last range was cut off on a phone** with nothing to
  say it scrolls — the same edge-shadow affordance the wide tables use now
  applies to it.
- **The Issues inbox showed the wrong columns first.** Eight columns plus the
  action group cannot fit a laptop, and the ones you triage on — status, count,
  last seen — were the ones pushed off-screen. Reordered so message and the 7-day
  shape are what you scroll for.
- **The test suite was red on a fresh checkout** (176 failures): Testbench's
  skeleton defaults the cache to the database store, whose table the in-memory
  test connection never migrates. Pinned to the array store in `phpunit.xml`.

### Added
- **A visual release gate.** `composer visual` boots a seeded throwaway app and
  screenshots every dashboard page in both themes at desktop and phone width,
  reporting unstyled tables, clipped content, sideways scroll, stuck lazy cards
  and failing requests, plus a contact sheet to review by eye. It also runs
  axe-core (WCAG 2.1 AA) on every page at both viewports, which is where the
  unlabelled form controls above came from. Releasing now requires it — see
  `RELEASING.md`. Native checkboxes also pick up the theme's accent colour
  instead of the OS blue.

## [0.9.1] - 2026-08-14

### Added
- **Three MCP tools for the new surfaces.** `footprint` (what Vigilance itself
  stores, and whether pruning keeps up), `suppressions` (list the active mute
  rules — always worth checking before concluding a route has no data, since a
  muted route looks exactly like an idle one — plus create/remove with writes
  on), and `incident-mode` (status is read-only; engaging needs both
  `mcp.allow_writes` and `incident_mode.enabled`, and is safe to hand to an
  agent precisely because the duration is capped and it expires itself).
  A test now pins "a tool for every dashboard page" so the claim cannot rot
  again — it had already gone stale when the Usage page shipped without one.

### Fixed
- **"A tool for every dashboard page" had stopped being true.** 0.9.0 shipped the
  Usage page without an MCP tool, and nothing checked. The claim is now an
  invariant a test enforces, with an explicit page-to-tool map so adding a page
  forces the coverage decision at review time rather than after release.

## [0.9.0] - 2026-08-14

**Run `php artisan migrate`** — this release adds one new table
(`vigilance_suppressions`) in its own migration. The base migration is
unchanged, so no `migrate:fresh` is needed.


### Added
- **Laravel Debugbar's counters, for production.** The **Routes** page now shows
  what each page *costs*, not only how long it takes: queries per request (avg
  and worst), time spent in the database, peak memory and Eloquent models
  hydrated — the four numbers you used to read at the bottom of the screen in
  local dev, aggregated per route.
  - Captured by a new `RequestProfile` APM recorder (`request_queries`,
    `request_db_ms`, `request_memory`, `request_models`), keyed by
    `[method, route]` so cardinality stays bounded.
  - Deliberately **not** built on tracing: a trace is only persisted when it is
    head-sampled, slow or errored, so a route that quietly runs 180 distinct
    queries in 400 ms — no N+1 shape, never slow enough to keep — was invisible.
    `RequestProfile` counts on every request instead; the hot path is two
    increments per query and the metrics are written once, in the terminate
    phase, after the response is sent.
  - Queries against `vigilance_*` tables are never counted, so monitoring does
    not inflate your own numbers (including on a dedicated connection).
  - Peak memory is rebased with `memory_reset_peak_usage()` on the HTTP path, so
    it stays per-request under Octane instead of reporting the heaviest request
    the worker ever served. The queue path is untouched, so job memory capture is
    unchanged.
  - **Off by default.** It listens to every query and writes 2–4 entries per
    request on top of the 2 the `Requests` recorder writes, so like tracing and
    the log explorer it is opt-in: `VIGILANCE_APM_REQUEST_PROFILE=true`. Drop
    `VIGILANCE_APM_REQUEST_PROFILE_SAMPLE` at high traffic — averages stay
    reliable, only the exact worst case blurs. The per-model hydration counter
    can be dropped on its own with `VIGILANCE_APM_REQUEST_PROFILE_MODELS=false`.
    The Routes page says how to switch it on when the cost columns are empty.
- **`heavy_request` alert rule** — fires when a route is expensive rather than
  slow: too many queries per request, or too high a memory peak. Off by default;
  thresholds (`queries`, `memory_mb`, `min_requests`, `window`) live under
  `alerts.rules.heavy_request`, and either threshold can be disabled with `0`.

- **Server resource alerts (CPU / memory / disk).** The `Servers` recorder has
  always collected these and nothing consumed them, so a filling disk was
  visible on the APM page and nowhere else. `ServerResourceRule` reads the
  snapshot already being written — one query, no extra collection — alerts per
  volume with the free space left, and escalates to critical past 97%. Hosts
  where detection is unsupported (0/0) and hosts whose heartbeat has gone stale
  are skipped rather than reported on frozen numbers. On by default.
- **A dead-man's switch for Vigilance's own pipeline.** A monitoring tool that
  dies quietly looks exactly like a healthy one. `MonitoringHealthRule` catches
  a server that stopped heartbeating (its resource alerts are silently dead
  too) and an ingest path that stopped writing while the app is still serving
  traffic — an idle app is explicitly not mistaken for a broken one.
  - It cannot cover its own absence: it runs from `vigilance:snapshot`, which is
    also what evaluates alerts, and a dead-man's switch cannot live inside the
    process it watches. Every run now records a `vigilance`/`snapshot`
    heartbeat so an external uptime check has something to read instead.
- **Disk usage over time, per volume.** Disk had no history at all — only the
  latest snapshot — so a volume filling up over days was invisible as a trend.
  Memory is now also recorded as a percentage alongside the absolute MB, since
  a 64 GB box and an 8 GB box do not compare on raw usage.

- **Auto-expiring incident mode.** One action keeps every trace, stops sampling
  anything out and lowers the log floor — then reverts on its own. The timer is
  the cache entry's TTL, so it expires even if the app is redeployed or nothing
  ever runs the scheduler again; a config change nobody makes is also one nobody
  reverts. Off by default (`VIGILANCE_INCIDENT_MODE`); when off it never even
  reads the cache, and when on the lookup is memoised to one read per request.
- **Latency-driven supervisor autoscaling** (`auto_scaling_strategy => 'latency'`).
  The existing strategies weight a pool by backlog × *average* runtime — an
  estimate, and an average is not a prediction when jobs run 50ms and 50s. This
  one uses the wait jobs actually experienced (p90 of `wait_ms`) and sizes the
  fleet by how far that is from `target_wait_ms`, so it is also the only
  strategy that scales the total back **down**: the others deploy every process
  the moment anything is queued and merely redistribute them.
- **Fleet size over time.** The supervisor's state table only ever held "right
  now", so a bad scaling decision left nothing to review. Worker counts are now
  sampled every 15 seconds and drawn as a step chart per pool, with toggleable
  series, on the Workers page.
- **Turn a finding into a rule in one click.** Muting a noisy route, a chatty
  cache key or a spammy exception meant editing `config/vigilance.php` and
  redeploying — which is why the noisy route was still noisy three weeks later.
  Rules can now be created next to the finding, take effect immediately, carry
  an optional expiry, and are listed on the page so a filter you cannot see
  cannot be forgotten.
- **Usage page.** What Vigilance itself stores: rows per telemetry type, what
  was written today, the oldest row, whether pruning is keeping up — and the
  config knob that turns each one down. For a tool that sells itself on being
  "bounded by design", it could not previously show its own footprint.
- **Re-run a completed run** with exactly the parameters it ran with. Kept
  separate from retry, and gated on the control plane and its allowlist:
  retrying a *failure* restores work that was meant to happen, re-running a
  *success* creates it a second time — the same charge, the same email — so the
  UI confirms first.
- **Staged control-plane changes** (`VIGILANCE_CONTROL_STAGING`). Queue several
  actions, review them in a persistent banner, apply them as one audited batch.
  Pausing four queues is otherwise four separate production changes with no
  moment in between to notice you picked the wrong connection.
- **Optional real-time dashboard** (`VIGILANCE_REALTIME`). Pages refresh from a
  broadcast when something actually happens instead of polling on a timer —
  live, with *less* database load rather than more. Vigilance does not ship
  Laravel Echo; it uses the app's, and falls back to polling when there is none.
  Broadcasts are throttled so a busy queue cannot cost more than the poll.
- **`vigilance:doctor` now checks the snapshotter itself**, and exits non-zero
  when it has gone silent. This closes the one gap the dead-man's switch cannot:
  `MonitoringHealthRule` is evaluated *by* `vigilance:snapshot`, so when the
  snapshotter stops, the rule stops with it and no alert can report the silence.
  Doctor runs in a separate process, which is what makes it usable as the
  outside observer — point an uptime check at it. Threshold:
  `metrics.snapshot_stale_after`.
- **A generic HTTP ingest exporter** (`apm.ingest.driver` or `exporters` set to
  `'http'`). Ships the same Entry/Value feed — aggregations included, so a
  receiver can roll it up the way local storage does — as batched JSON to any
  endpoint, with an optional bearer token and custom headers. Failures are
  swallowed: an external sink is strictly additive and can never break local
  capture.
  - Deliberately **not** a Nightwatch driver. Nightwatch ingests through its own
    agent (`NIGHTWATCH_INGEST_URI` plus an environment token) and publishes no
    third-party ingest format, so such a driver could only be a
    reverse-engineered protocol that breaks the first time they change it while
    calling itself an integration. Point the exporter at your own endpoint — an
    OTel collector, a Lambda, a shim — and shape the payload there.
- **One time-range control everywhere**, remembered across pages, now including
  **15m** — which required adding a matching bucket period, since a window with
  no period silently reads back as zero.

### Changed
- **The dashboard moved to Tailwind v4 and a vendored BlatUI component kit.**
  The stylesheet is now CSS-first (`resources/css/vigilance.css`; no
  `tailwind.config.js`), and the UI kit is vendored into
  `resources/views/components/ui/`, used as `<x-vigilance::ui.card>`.
  - **Nothing changes for consumers**: the dashboard still ships a prebuilt,
    self-contained stylesheet and needs no Vite, npm or Tailwind in the host app.
    The namespace keeps the kit from colliding with an app's own `<x-ui.*>`.
  - Vigilance's `--v-*` tokens stay authoritative — they are contrast-tuned —
    and the component tokens alias them, so both kits share one palette and the
    views can migrate page by page without the dashboard looking half-finished.
  - Only static components are vendored: Livewire already bundles Alpine and a
    second copy would conflict. Native form controls are styled with
    `.blat-input` / `.blat-select` / `.blat-checkbox` instead, no JS.
  - Adds one small runtime dependency, `gehrisandro/tailwind-merge-laravel`,
    registered by Vigilance itself so it works even with `dont-discover`.
  - Interactive components (dialog, dropdown, tooltip, tabs) are bundled with
    esbuild into `resources/dist/vigilance.js`, which deliberately contains no
    Alpine: Livewire already ships one and two would fight over the same DOM.
  - Alpine-driven shell chrome stays plain markup — Alpine's `:attr` shorthand
    and Blade's component prop binding are the same syntax, so `:aria-expanded`
    on a component gets evaluated as PHP. A test pins that down.

### Fixed
- **The Usage page read back empty on PostgreSQL.** It dates `vigilance_logs` by
  `logged_at`, which stores unix seconds, but compared it against a datetime.
  SQLite is loosely typed and accepted it; PostgreSQL rejected the comparison
  and — being PostgreSQL — aborted the surrounding transaction, so every table
  after it in the loop came back blank. The column and its storage kind now live
  in one map, checked against the real schema by a test that runs on every
  database in CI.
- **The supervisor could exceed `max_processes`.** Each pool's share of the
  fleet was rounded independently, so two pools splitting an exact half of 10
  both rounded up and eleven workers started for a documented maximum of ten.
  The split now uses largest-remainder and the parts add up to the whole.
- **A published config never saw anything added in a later release.** Laravel's
  `mergeConfigFrom` is shallow — it `array_merge`s only the top-level `vigilance`
  key — so a `config/vigilance.php` published by `vigilance:install` kept its own
  `apm`, `alerts`, … sub-arrays wholesale. Every recorder, alert rule and option
  shipped after that publish silently never registered, with no error to explain
  why (the `Requests` recorder added in 0.7 was affected the same way). The
  provider now merges recursively, so packaged defaults reach existing installs
  while any key you actually define still wins. List-shaped values (ignore
  patterns, allow/deny lists, SLO definitions) are **replaced**, never appended
  to, so narrowing a default list still works.
  - Note: if you disabled a recorder by *deleting* its entry rather than setting
    `'enabled' => false`, it comes back at its packaged default. Set the flag.
- **N+1 alerts were never tracked as incidents on MySQL/PostgreSQL.** The alert
  key fell back to the offending SQL — up to 500 characters — and overflowed the
  `string(255)` `incidents.key` column. The insert is rescued, so the
  notification fired but no incident row was written: nothing to count
  occurrences on, nothing to auto-resolve. Alert keys are now bounded.
- **Livewire requests minted one metric key per visited record.** Livewire
  updates are attributed to the referring page, but the referrer is a concrete
  URL, so `/orders/42`, `/orders/43`, … each became their own key — unbounded
  cardinality, the very thing keying by route exists to prevent. The referrer is
  now collapsed to the route URI that serves it (`/orders/{order}`), falling back
  to the concrete path when it matches no route. Affects the `Requests`,
  `SlowRequests` and `RequestProfile` recorders.

## [0.8.3] - 2026-07-30

### Added
- **Wrapped exceptions now report their real cause, not the envelope.** A
  Blade/Livewire `ViewException` is almost always a wrapper — the actual fault
  (e.g. `Call to a member function newQueryWithoutRelationships() on null`) sits
  several `getPrevious()` levels down. Issue capture now unwinds the chain and
  fingerprints, names and samples by the **root cause**, so:
  - Two unrelated bugs that happen to share a wrapper message no longer collapse
    into one issue, and the same bug no longer splits when the wrapping depth
    varies between occurrences.
  - The repeated `(View: …)` suffix Blade/Livewire tack on at each layer is
    de-duplicated out of the message (it was pure noise and destabilised the
    fingerprint).
  - The stack-trace sample leads with the root cause and its frames, lists the
    wrappers compactly, and promotes the first **application** frame as the issue
    culprit — instead of the wrapper's `handleViewException` frame 0.
  - Errors raised during a `livewire/update` request now carry the **Livewire
    component** (recovered from the request payload) as the issue's culprit and a
    `livewire` context tag — the opaque `/livewire/update` URL named nothing;
    the component names everything (Sentry-style `livewire?component=…`).
  - **Queue failures** are unwrapped the same way: a failed job records its
    `exception_class`/`exception_message`, groups, and stores its stack sample by
    the root cause rather than the wrapper.

## [0.8.2] - 2026-07-23

### Added
- **Aggregate alerts now name the culprit, not just a count.** The
  failure-rate, exception-spike and slow-request-rate alerts spell out the worst
  offenders inline: the top failing jobs/commands with their exception, the top
  exception classes with their `file:line`, and the slowest routes with their max
  latency — so the incident is actionable without opening a dashboard. The
  long-running-job alert now includes the run id.

## [0.8.1] - 2026-07-23

### Added
- **N+1 incidents now point at the exact query and the exact line.** Query spans
  capture the application frame (`file:line`) that ran them, so a detected N+1
  carries the repeated SQL *and* the offending code location — surfaced on the
  trace page, in the promoted `n_plus_one` APM signal, and in the alert/incident
  message itself. No more hunting through the trace to find which query, from
  where. A new `Vigilance\Support\CodeLocation` helper resolves the nearest app
  frame (skipping vendor and Vigilance's own frames).

## [0.8.0] - 2026-07-23

A broad gap-closing pass across the observability surface (error tracking,
tracing, alerting, metrics, capture and the MCP server).

### Added
- **Breadcrumbs — the trail before an error.** A bounded, per-unit-of-work trail
  (`Vigilance::breadcrumb()` + automatic log capture) attached to an issue,
  latest-occurrence-wins like Sentry. Cleared at each request/job boundary;
  rendered as a timeline on the issue page and surfaced via the MCP `issue` tool.
  New `issues.breadcrumbs` config.
- **Distributed trace propagation.** Continue an upstream trace from a W3C
  `traceparent` header, carry it across the queue boundary onto dispatched jobs
  (job links back to what enqueued it), and emit `traceparent` on outgoing HTTP
  so downstream services join the trace. New `tracing.propagation` config.
- **Manual issue merge.** Merge one issue into another when fingerprinting split
  the same problem — occurrences/runs move to the canonical group, the source is
  hidden, and future occurrences of its signature are redirected. From the issue
  page or the `merge-issues` MCP tool.
- **Maintenance windows.** Suppress alert notifications during planned
  maintenance — ad-hoc (`vigilance:maintenance`) or recurring (config) — so a
  deploy doesn't page anyone; the next cycle re-evaluates after it closes.
  Also a `maintenance` MCP tool.
- **First-class N+1 signal + alert.** N+1 patterns the tracer detects are
  promoted to an aggregatable APM signal keyed by route/job, with a new
  `long_running_job`- and `n_plus_one`-style opt-in alert rule.
- **Long-running-job detection.** A rule that alerts on jobs stuck in "running"
  past a threshold (runaway/stuck workers backlog/failure rules can't see).
- **Richer custom metrics.** `Vigilance::histogram()` / `timing()` distributions
  (avg, max, p50/p95/p99) and `decrement()`, on the dashboard and the MCP tool.
- **User-feedback widget endpoint.** An opt-in public `POST {path}/feedback`
  endpoint tying a user-reported problem to their trace; read via the `feedback`
  MCP tool. New `feedback` config.
- **Job capability tags.** Jobs are tagged `unique` / `encrypted` from their
  queue contracts, so those traits are visible and filterable on the run.
- **Retry lineage.** A manually retried run's `retry_of` is now actually set
  (the marker was written at dispatch but never read), so retry chains show.
- **New MCP tools.** `routes` (per-route p50/p95/p99), `workload` (system load +
  job-class breakdown), `record-deploy`, `assign-issue`, plus the `maintenance`,
  `merge-issues` and `feedback` tools above — closing dashboard/MCP parity.

### Fixed
Three defects surfaced by end-to-end testing against a real Laravel app:
- **Outgoing `traceparent` was never emitted.** The registration guard probed
  `method_exists()` on the Http *facade* (always false — the facade proxies via
  `__callStatic`), so the global request middleware was never installed and
  distributed propagation silently stopped at the app edge. Fixed.
- **Encrypted jobs never got the `encrypted` tag.** Capability tags were derived
  from the command object, which can't be reconstructed from an encrypted job's
  opaque payload; they're now derived from the class name so the tag applies.
- **Retry lineage emitted an `E_DEPRECATED`.** It stamped a dynamic property on
  the job (fatal on PHP 9, and not a `Throwable` so the surrounding catch never
  suppressed it); the parent run id now travels through the manual context.

### Notes
Deferred as out of scope for this pass (each for a concrete reason): continuous
profiling and DOM session replay (need extra infrastructure), OTLP trace export
(follow-up on the existing Ingest/TraceStorage seam), a slow-cache recorder
(cache events carry no duration), custom-metric dimensional tags, job chain
lineage (Laravel models no shared chain id), and deploy markers overlaid on
charts. First-class batches (progress/cancel/retry) and log↔trace correlation
already existed. Storage percentiles/aggregation remain sqlite/mysql/pgsql only.

## [0.7.0] - 2026-07-23

### Added
- **Per-queue pause — stop draining one queue while the rest keep running.**
  The supervisor now honours a per-queue pause flag: it narrows each pool to its
  still-active queues (a `balance=false` pool serving several queues is relaunched
  bound to only its unpaused ones) and holds a fully-paused pool at zero workers
  without ever spinning workers up just to tear them down. Pauses can be timed
  (auto-resume after N seconds) or indefinite, are delivered through the same
  cache-flag channel as the global pause (so they work on every driver and OS),
  and deliberately survive a supervisor restart or deploy until resumed or lapsed.
  Drive it from the **Workload** page (Pause / 15m / 1h / Resume per queue, plus a
  strip listing paused-but-idle queues), or from the CLI:
  `vigilance:pause --queue=emails [--connection=redis] [--for=900]` and
  `vigilance:continue --queue=emails`. `vigilance:status` lists paused queues and
  their expiry. Pausing is an operational lever and is always available.
- **Clear a queue from the dashboard.** A driver-agnostic purge (database, redis, sqs
  — anything implementing `ClearableQueue`; beanstalkd is not supported) exposed as a **Clear**
  button per queue on the Workload page.
- **Cancel individual pending jobs.** Select waiting jobs on the **Pending** page
  (database driver) and cancel them by id.
  Both destructive operations require the manual-control master switch
  (`VIGILANCE_CONTROL_ENABLED=true`), are guarded behind a confirm dialog, and are
  written to the same audit log as every other manual action.
- **Full worker & queue control over MCP.** Five new MCP tools let an AI agent
  drive the control plane against live data, not just read it: `control-workers`
  (pause / resume / restart / terminate the fleet), `pause-queue` / `resume-queue`
  (single queue, optionally timed), `clear-queue` (purge a backlog on
  database/redis/sqs) and `cancel-pending` (delete waiting jobs by id, database
  driver). All self-gate
  on `VIGILANCE_MCP_ALLOW_WRITES`; the two destructive ones additionally require
  `VIGILANCE_CONTROL_ENABLED` — and every call is audited. The read tools now
  surface the state to act on: `workers` reports the global control status and
  `queues` flags each paused queue plus lists their auto-resume expiry.

### Fixed
- **Duplicate "queued" job runs under Laravel Octane on Vapor.** Vapor's Octane
  runtime boots the application twice in the same PHP process (once for
  `config:cache`, then again for the worker). Because `Queue::createPayloadUsing`
  registers into a process-static array, the payload hook stacked on the second
  boot and every dispatch wrote two `Queued` records with the same UUID — only
  one of which was ever advanced to `Running`/`Succeeded`, leaving a permanent
  ghost row. Registration is now guarded so the hook is installed once per
  process regardless of how many times the service provider boots.
- **Static analysis stayed clean against newer Larastan.** The APM aggregate
  SQL builder used inline `match` expressions whose default arm a newer Larastan
  proved unreachable (`match.alwaysTrue`) while older versions required it — an
  irreconcilable pair for the floating dev toolchain. The two branches are now
  extracted into `tailAggregate()` / `bucketAggregate()` helpers that take a
  plain `string`, so the default stays reachable and analysis is version-stable.
  No behavioural change.

## [0.6.1] - 2026-06-26

### Fixed
- **Incident occurrence counts no longer drift low under concurrency.**
  `AlertManager` recorded a recurring incident by writing back
  `occurrences + 1` read into PHP, so two nodes (or scheduler runs) recording the
  same incident at once could clobber each other and undercount. The bump is now
  an atomic SQL increment, applying the same concurrency-safe pattern already used
  for failure-group occurrences in 0.5.5.

### Documentation
- **MySQL durability tuning for the dedicated monitoring connection.** Documented
  trading strict durability for insert throughput on a dedicated monitoring MySQL
  instance (`innodb_flush_log_at_trx_commit = 2`, `innodb_flush_log_at_timeout = 5`)
  — safe because the connection only carries telemetry and queued work is retried,
  with an explicit warning never to apply it to a connection the app also uses.

## [0.6.0] - 2026-06-17

### Added
- **MCP server — query Vigilance from your AI agent.** A new, optional
  [Model Context Protocol](https://modelcontextprotocol.io) server (built on the
  official `laravel/mcp`) exposes Vigilance to an AI coding agent (Claude Code,
  Cursor, …) so it can investigate and fix problems against live data. Enable with
  `VIGILANCE_MCP_ENABLED=true` and run `php artisan mcp:start vigilance`. The tools
  cover **every dashboard page** — overview; issues & exceptions; runs & per-job
  metrics; APM (route performance, slow requests / queries / jobs / outgoing HTTP,
  cache hit-rate, servers, per-user usage); RUM Core Web Vitals; traces; logs;
  SLOs; incidents; release health; the worker/queue fleet (workers, queues,
  pending, scheduled tasks, batches, tags); and custom business metrics. All read
  tools are **read-only by default**, with every payload passed through the same
  secret redaction as storage and bounded by `mcp.max_results` /
  `mcp.max_field_length` caps, so a tool can never leak a secret or dump the
  database into the agent's context. Setting `VIGILANCE_MCP_ALLOW_WRITES=true`
  additionally exposes audited triage tools (resolve / acknowledge / mute / reopen
  an issue, retry a failed job or a whole issue). **Manual control** (dispatch a
  job, run an artisan command) is double-gated — it needs both
  `VIGILANCE_MCP_ALLOW_WRITES=true` and the dashboard's `VIGILANCE_CONTROL_ENABLED`
  — and obeys the same `control` allowlist. While a capability is off, its tools
  are not even advertised to the client, and every write is recorded in the same
  audit log as a dashboard action. An optional HTTP transport
  (`VIGILANCE_MCP_WEB_ENABLED`) is always wrapped in the dashboard's
  `viewVigilance` authorization. `laravel/mcp` is an **optional** dependency
  (`composer require laravel/mcp`); the feature is a no-op without it. New `mcp`
  config section, a guide in `docs/mcp.md`, and updated Laravel Boost
  guidelines/skill.

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
