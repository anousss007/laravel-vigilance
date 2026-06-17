# Vigilance MCP server (query your telemetry from an AI agent)

Vigilance can expose its observability data to an AI coding agent over the
[Model Context Protocol](https://modelcontextprotocol.io) — built on the official
[`laravel/mcp`](https://github.com/laravel/mcp) package. Your agent (Claude Code,
Cursor, Copilot, …) can then investigate and **fix** problems against live data:
"check the new errors since the last deploy and fix them", "what's slow on the
checkout route", "retry the failed import jobs".

Where a hosted error tracker's MCP usually only sees errors, the Vigilance server
spans **errors, queue/command/scheduler runs, APM performance, traces, logs,
SLOs, incidents and release health** — all self-hosted, and all from the same
data the dashboard renders. Because the agent runs inside your project, the loop
is complete: it reads the issue, opens the `file:line` from the stacktrace, and
edits the code.

> This is the runtime counterpart to the [Laravel Boost](#how-it-relates-to-laravel-boost)
> integration. **Boost teaches the agent how to code with Vigilance; the MCP
> server gives it your live data.**

## Installation

The MCP server is **optional** and **off by default**. It needs the `laravel/mcp`
package, which Vigilance does not pull in for you:

```bash
composer require laravel/mcp
```

Enable the server:

```env
VIGILANCE_MCP_ENABLED=true
```

That's it — Vigilance registers a local (stdio) server named `vigilance`. Nothing
is exposed over the network unless you explicitly opt in to the
[web transport](#remote-access-http-transport).

## Connecting your MCP client

The server runs as an ordinary Artisan command over stdio. Point your client at:

```
php artisan mcp:start vigilance
```

For **Claude Code**:

```bash
claude mcp add vigilance -- php artisan mcp:start vigilance
```

For a generic client (Cursor, Copilot, the MCP Inspector, …), use the standard
stdio server config:

```json
{
  "mcpServers": {
    "vigilance": {
      "command": "php",
      "args": ["artisan", "mcp:start", "vigilance"]
    }
  }
}
```

Run it from your project root (so `artisan` resolves). You can sanity-check the
server with `laravel/mcp`'s inspector: `php artisan mcp:inspector vigilance`.

## The tools

### Read-only (always available)

| Tool | Purpose |
|---|---|
| `overview` | Health summary for a window: run counts + success rate, top unresolved issues, recent failures, slowest runs. **Start here.** |
| `issues` | List grouped error issues. Filter by `status` (open/resolved/muted/acknowledged/all), `source`, or search `q`. |
| `issue` | One issue in full: stacktrace sample, context, release info, and the recent runs that triggered it. |
| `runs` | List job/command/scheduler runs. Filter by `type`, `status`, `queue`, or search `q`. |
| `run` | One run in full: parameters, output, exit code, full exception, timing, resources, retry lineage. |
| `performance` | Per-route HTTP performance over a window: throughput, error rate, Apdex, p50/p95/p99. |
| `slow-queries` | Slowest DB queries (by SQL + the code location that issued them). |
| `slow-jobs` | Slowest queued jobs by class. |
| `servers` | Per-server CPU / memory / disk and online status. |
| `slow-requests` | Slowest HTTP requests by route (threshold-based; complements `performance`). |
| `slow-http` | Slowest outgoing HTTP calls your app made (grouped by host). |
| `cache` | Cache hit-rate and the most-missed keys. |
| `exceptions` | APM exception counts grouped by class + code location. |
| `usage` | Top users by request / job activity. |
| `traces` | List recent request/job traces (filter by type, status, slow-only, search). |
| `trace` | One trace's span waterfall plus its correlated log lines. |
| `logs` | Search application logs (by level, channel, trace id, or term). |
| `slos` | SLO attainment, error budget remaining, and burn rate. |
| `incidents` | Fired alerts as incidents (open/resolved, occurrences, MTTR). |
| `releases` | Per-deploy health: error-rate / latency / throughput after vs. before, with a verdict. |
| `vitals` | Real User Monitoring — Core Web Vitals (p75 LCP/INP/CLS/FCP/TTFB) per page, with ratings. |
| `custom-metrics` | Custom counters & gauges from `Vigilance::increment()` / `gauge()`. |
| `workers` | The supervisor/worker fleet across nodes (the Horizon-replacement view). |
| `queues` | Per-queue depth, worker count, throughput, wait and time-to-clear. |
| `pending` | Jobs currently waiting in the (database) queue backend. |
| `job-metrics` | Per-job-class run counts, failures, duration and memory/CPU. |
| `schedule` | Scheduled-task monitors: cron, last run, and late/failed flags. |
| `batches` | Job batches (Laravel bus batches) with progress. |
| `tags` | Tags seen on recent runs, with counts and which are pinned. |

### Writes (opt-in)

Disabled until you set `VIGILANCE_MCP_ALLOW_WRITES=true`. While off, these tools
are **not even listed** to the client, so the agent cannot call them.

| Tool | Purpose |
|---|---|
| `resolve-issue` | Mark an issue resolved. |
| `reopen-issue` | Reopen a resolved issue. |
| `acknowledge-issue` | Acknowledge an issue and assign it to the MCP actor. |
| `mute-issue` | Mute an issue for N hours. |
| `retry-run` | Retry one failed queued job. |
| `retry-issue` | Retry every failed job in an issue, then resolve it. |

Every write is written to Vigilance's **audit log** (`vigilance_audit`), attributed
to `mcp` (or `mcp:<user>` over an authenticated web transport) — exactly like a
manual action taken from the dashboard. Retries re-dispatch the original job via
the same path as the dashboard's retry, so lineage and capture are preserved.

### Manual control (opt-in, double-gated)

Dispatching jobs and running artisan commands is **off** unless you enable **both**
`VIGILANCE_MCP_ALLOW_WRITES=true` **and** the dashboard's own
`VIGILANCE_CONTROL_ENABLED=true`. The same `control` allowlist that governs the
dashboard governs these tools; the two discovery tools are read-only.

| Tool | Purpose |
|---|---|
| `dispatchable-jobs` | List the jobs allowed for dispatch (or inspect one job's parameters). Needs `control.enabled`. |
| `runnable-commands` | List the allowed artisan commands (or inspect one command's arguments/options). Needs `control.enabled`. |
| `dispatch-job` | Dispatch an allowlisted job with arguments. Needs `control.enabled` **and** `allow_writes`. |
| `run-command` | Run an allowlisted artisan command. Needs `control.enabled` **and** `allow_writes`. |

A job or command that is not on the allowlist is rejected exactly as it would be
from the dashboard, and every dispatch/run is recorded in the audit log.

## Safety model

The server is built to be safe to point at a production app:

- **Read-only by default.** Writes require an explicit opt-in and are otherwise
  invisible.
- **Secret redaction.** Every payload (run parameters, exception context, log
  context, trace attributes) is passed through the same `vigilance.redact` key
  filter as storage, so values under keys like `password`, `token`, `api_key`
  are `[redacted]` before they reach the agent.
- **Bounded output.** `vigilance.mcp.max_results` caps how many rows a list tool
  returns; `vigilance.mcp.max_field_length` truncates long fields (stacktraces,
  command output, context blobs). A tool can never dump the whole database into
  the agent's context window.
- **Audited writes.** See above.
- **Local by default.** The stdio server is reachable only by someone who can
  already run `php artisan` on the box — the same trust level as `tinker`.

## Remote access (HTTP transport)

For a remote agent you can serve the same tools over HTTP. This is **off by
default** and, when enabled, is always wrapped in the dashboard's own
authorization (`Vigilance::auth()` / the `viewVigilance` gate) on top of whatever
middleware you configure — so it stays local-only until you explicitly authorize
access.

```env
VIGILANCE_MCP_WEB_ENABLED=true
VIGILANCE_MCP_WEB_PATH=vigilance/mcp
```

For production remote use, add real token authentication (Laravel Sanctum or
Passport) to `vigilance.mcp.web.middleware`; see the
[`laravel/mcp` authentication docs](https://laravel.com/docs/mcp#authentication).

## How it relates to Laravel Boost

They are complementary, not redundant:

- **Boost** (static): ships Vigilance's AI guidelines and a `vigilance-development`
  skill, so the agent knows the package's conventions when writing code.
- **MCP** (runtime): answers questions about what is actually happening in your
  app right now, and lets the agent act on it.

Use Boost to write Vigilance-aware code; use MCP to debug a live app with it.

## Configuration

All options live under `mcp` in `config/vigilance.php`:

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `VIGILANCE_MCP_ENABLED` | `false` | Register the MCP server at all. |
| `name` | `VIGILANCE_MCP_NAME` | `vigilance` | Local server name (`php artisan mcp:start <name>`). |
| `allow_writes` | `VIGILANCE_MCP_ALLOW_WRITES` | `false` | Expose the write/triage tools. |
| `max_results` | — | `50` | Max rows any list tool returns. |
| `max_field_length` | — | `4000` | Truncation length for long string fields. |
| `web.enabled` | `VIGILANCE_MCP_WEB_ENABLED` | `false` | Also serve over HTTP. |
| `web.path` | `VIGILANCE_MCP_WEB_PATH` | `vigilance/mcp` | HTTP route for the web server. |
| `web.middleware` | — | `['web']` | Middleware for the web server (the dashboard gate is always added on top). |
