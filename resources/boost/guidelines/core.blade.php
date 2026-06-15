## Vigilance

`anousss007/vigilance` is a driver-agnostic **control center** for Laravel queues, jobs, commands and the scheduler: full lifecycle capture, a worker supervisor (a Horizon replacement that runs on **any** queue driver), whole-app APM, per-request/job tracing, manual job/command dispatch, and a full **observability suite** — a unified Issues error tracker, per-route performance (p50/p95/p99 + Apdex), Real User Monitoring of Core Web Vitals, SLOs with error budgets, custom business metrics, a trace-correlated log explorer, and rule-based alerting with incident tracking. The standalone Livewire dashboard lives at `/vigilance` (configurable via `vigilance.path`). Every option is documented inline in `config/vigilance.php`.

### Conventions (follow these — don't reinvent them)

- **Secure by default.** The dashboard is local-only until the app authorizes it. Grant access with a `viewVigilance` Gate (preferred, like `viewHorizon`) or `Vigilance::auth()`. Never widen `vigilance.middleware` to make it public.
- **Capture is automatic and driver-agnostic.** Jobs, artisan commands and scheduled tasks are recorded from framework events — do **not** add manual logging/tracking around them. Silence noisy work with the `Vigilance\Contracts\ShouldNotBeMonitored` marker or the `except.jobs` / `except.commands` config; don't disable Vigilance globally.
- **Production-bounded by design:** dispatch-time sampling (`capture.sample_rate`; failures are always kept), size caps, secret redaction (`redact`), and an optional dedicated `storage.connection`. Use these knobs instead of writing custom throttling.
- **Manual control is OFF by default** (it is effectively remote code execution). Enable with `VIGILANCE_CONTROL_ENABLED=true` and govern it with the `control.jobs` / `control.commands` allowlists. Opt a job in to dashboard dispatch with the `Vigilance\Contracts\Dispatchable` marker; hide a side-effecting job with `Vigilance\Contracts\ShouldNotBeDispatchedManually`.
- **The worker supervisor replaces `queue:work` / Horizon.** Run `php artisan vigilance:supervise` (configured under `vigilance.environments`) as the long-running worker process; it auto-scales on database, redis, sqs and beanstalkd. Do **not** run it against the same queues as Horizon.
- **APM & tracing:** run `php artisan vigilance:check` as a heartbeat on each app server. Tracing is off by default — enable with `VIGILANCE_TRACING=true` (it tail-samples: only slow/errored/sampled traces are stored).
- **Observability is automatic where it can be.** Exceptions (web/queue/command/`Vigilance::report()`) are grouped into the **Issues** inbox and all requests are rolled up per route — no setup. The opt-in layers are: **RUM** (`VIGILANCE_RUM=true` + the `@vigilanceRum` Blade directive in your layout `<head>`), the **log explorer** (`VIGILANCE_LOGS=true`, correlated to traces), and **SLOs** (define them under `vigilance.slos`). Don't hand-roll these — use the built-ins.
- **Custom business metrics** go through `Vigilance::increment($name, $by = 1)` (counter) and `Vigilance::gauge($name, $value)` (gauge); they auto-appear on the Custom Metrics page. Don't build a parallel metrics table.

### Authorize the dashboard

@verbatim
<code-snippet name="Grant dashboard access (a service provider's boot method)" lang="php">
use Illuminate\Support\Facades\Gate;

Gate::define('viewVigilance', fn ($user) => $user->isAdmin());
// Or a closure instead of a gate:
// \Vigilance\Vigilance::auth(fn ($request) => $request->user()?->isAdmin());
</code-snippet>
@endverbatim

### Expose a job for manual dispatch from the dashboard

@verbatim
<code-snippet name="Opt a queued job in to manual dispatch" lang="php">
use Illuminate\Contracts\Queue\ShouldQueue;
use Vigilance\Contracts\Dispatchable;

class ProcessPodcast implements ShouldQueue, Dispatchable
{
    public static string $vigilanceLabel = 'Process a podcast';

    // The constructor is reflected to build the dispatch form (scalars, enums,
    // dates, and Eloquent models resolved via findOrFail).
    public function __construct(public Podcast $podcast, public bool $notify = true) {}
}
</code-snippet>
@endverbatim

### Route alerts straight from .env (no service provider needed)

@verbatim
<code-snippet name=".env — where queue-backlog / failure-rate / SLO-burn / health alerts go" lang="ini">
VIGILANCE_ALERT_EMAILS=ops@example.com,cto@example.com
VIGILANCE_SLACK_WEBHOOK=https://hooks.slack.com/services/...
VIGILANCE_DISCORD_WEBHOOK=https://discord.com/api/webhooks/...
VIGILANCE_TEAMS_WEBHOOK=https://outlook.office.com/webhook/...
VIGILANCE_ALERT_WEBHOOKS=https://events.pagerduty.com/...   # one or comma-separated
</code-snippet>
@endverbatim

Prefer code? `Vigilance::routeMailNotificationsTo([...])`, `routeSlackNotificationsTo($url)`, `routeDiscordNotificationsTo($url)`, `routeTeamsNotificationsTo($url)`, `routeWebhooksTo([...])`, or `Vigilance::alertUsing(fn ($alert) => ...)` for a custom channel — an explicit call overrides the `.env` values. Fired alerts are persisted as **incidents** (auto-resolved when they stop recurring) with MTTR on the Incidents page.

### Record a custom business metric / collect Web Vitals

@verbatim
<code-snippet name="Custom metrics anywhere + RUM in your layout" lang="php">
use Vigilance\Vigilance;

Vigilance::increment('signups');                // counter → Custom Metrics page
Vigilance::gauge('cart_value', $cart->total()); // gauge (avg / peak / min)
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Enable RUM (VIGILANCE_RUM=true), then in your Blade layout head" lang="blade">
<head>
    {{-- ... --}}
    @vigilanceRum
</head>
</code-snippet>
@endverbatim

### Run the operational processes (instead of queue:work / horizon)

@verbatim
<code-snippet name="Long-running processes" lang="bash">
php artisan vigilance:supervise   # worker supervisor + autoscaler (any driver)
php artisan vigilance:check       # APM heartbeat (one per app server)
</code-snippet>
@endverbatim

### Schedule maintenance

@verbatim
<code-snippet name="routes/console.php (or the scheduler)" lang="php">
use Illuminate\Support\Facades\Schedule;

Schedule::command('vigilance:prune')->daily();           // trim old runs + snapshots
Schedule::command('vigilance:snapshot')->everyFiveMinutes(); // metrics + alert eval
Schedule::command('vigilance:schedule-sync')->hourly();  // keep task monitors current
</code-snippet>
@endverbatim

Other helpers: surface a swallowed exception to the APM exception card with `Vigilance::report($e)`; run code without self-monitoring via `Vigilance::withoutRecording(fn () => ...)`; run `php artisan vigilance:doctor` to diagnose a misconfigured install. For deeper, task-specific patterns (supervisor tuning, custom alert rules, the APM `Ingest` seam), use the **vigilance-development** skill.
