<?php

use Vigilance\Apm\Recorders\CacheInteractions;
use Vigilance\Apm\Recorders\Exceptions;
use Vigilance\Apm\Recorders\Logs;
use Vigilance\Apm\Recorders\Mail;
use Vigilance\Apm\Recorders\Notifications;
use Vigilance\Apm\Recorders\Queues;
use Vigilance\Apm\Recorders\Requests;
use Vigilance\Apm\Recorders\Servers;
use Vigilance\Apm\Recorders\SlowJobs;
use Vigilance\Apm\Recorders\SlowOutgoingRequests;
use Vigilance\Apm\Recorders\SlowQueries;
use Vigilance\Apm\Recorders\SlowRequests;
use Vigilance\Apm\Recorders\UserJobs;
use Vigilance\Apm\Recorders\UserRequests;
use Vigilance\Support\Defaults;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When disabled, Vigilance registers nothing on the queue/command/schedule
    | pipelines and records no data. The dashboard routes are still registered
    | so you can browse historical data, but no new runs are captured.
    |
    */

    'enabled' => env('VIGILANCE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | "path" is the URI prefix the dashboard is served from. "domain" lets you
    | serve it from a subdomain. "middleware" wraps every dashboard route; the
    | "Authorize" middleware calls Vigilance::check(), which authorizes via a
    | "viewVigilance" Gate ability (or a Gate::before rule, or Vigilance::auth()),
    | falling back to "local environment only" when none is defined.
    |
    */

    'path' => env('VIGILANCE_PATH', 'vigilance'),

    'domain' => env('VIGILANCE_DOMAIN'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Vigilance persists everything to its own tables, which makes it fully
    | driver-agnostic (database, Redis, SQS, Beanstalkd, sync). You may point
    | it at a dedicated database connection to keep monitoring writes off your
    | primary connection.
    |
    */

    'storage' => [
        'connection' => env('VIGILANCE_DB_CONNECTION'),
        'driver' => 'database',
    ],

    // The current release identifier — tags issues (first seen / regressed in)
    // and deploy markers, and labels release health. Falls back to app.version.
    'release' => env('VIGILANCE_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Capture
    |--------------------------------------------------------------------------
    |
    | Fine-grained control over what gets recorded and how much overhead the
    | recorder is allowed to add to your workers.
    |
    |  - store_parameters: persist the (redacted) job constructor properties
    |    and command arguments/options so you can see "what it ran with".
    |  - store_for_retry: keep the serialized job so it can be retried later
    |    even if the queue's failed_jobs entry is gone.
    |  - sample_rate: fraction (0.0 - 1.0) of *successful* runs to record.
    |    Failures are always recorded regardless of sampling.
    |
    */

    'capture' => [
        'jobs' => env('VIGILANCE_CAPTURE_JOBS', true),
        'commands' => env('VIGILANCE_CAPTURE_COMMANDS', true),
        'schedule' => env('VIGILANCE_CAPTURE_SCHEDULE', true),

        'store_parameters' => true,
        'store_for_retry' => true,
        'capture_memory' => true,
        'capture_cpu' => true,

        'sample_rate' => (float) env('VIGILANCE_SAMPLE_RATE', 1.0),

        // Max characters kept for parameter blobs, exception traces and output.
        'max_parameter_length' => 16384,
        'max_exception_length' => 8192,
        'max_output_length' => 16384,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exclusions
    |--------------------------------------------------------------------------
    |
    | Jobs and commands listed here are never recorded. Vigilance's own work
    | and the long-running worker/scheduler commands are excluded by default
    | to avoid noise and feedback loops. A job may also opt out by implementing
    | Vigilance\Contracts\ShouldNotBeMonitored.
    |
    | Long-running daemons (octane:start, reverb:start, pulse:work, queue:work,
    | schedule:work, horizon, …) are ALSO excluded unconditionally in code, even
    | if removed from this list: a command run is start→finish, and a daemon
    | would otherwise sit "running" forever — and be left dangling on shutdown.
    |
    */

    'except' => [
        'jobs' => [
            // App\Jobs\NoisyJob::class,
        ],
        'commands' => array_merge([
            'queue:work',
            'queue:listen',
            'schedule:run',
            'schedule:finish',
            'schedule:work',
            'horizon',
            'horizon:*',
            'octane:start',
            'octane:reload',
            'octane:stop',
            'octane:status',
            'reverb:start',
            'pulse:work',
            'pulse:check',
            'vigilance:*',
            'boost:*',
            'package:discover',
        ], Defaults::frameworkCommands()),
    ],

    /*
    |--------------------------------------------------------------------------
    | Globally ignored paths
    |--------------------------------------------------------------------------
    |
    | Request paths listed here are excluded from ALL request-level telemetry at
    | once — APM (slow requests + per-route metrics), tracing, Real User
    | Monitoring, and web-request error capture. Use it for endpoints you don't
    | care to monitor: an admin panel, a package's routes, health checks, etc.
    | Each entry is a wildcard ("/admin/*", "livewire-*") or a delimited regex
    | ("#^/internal/#"). The dashboard, Livewire and Telescope/Horizon are
    | already excluded by the per-recorder defaults.
    |
    */

    'ignore_paths' => [
        // '/admin/*',
        // '/livewire/*',
        // '/up',
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual control (dispatch jobs / run commands from the dashboard)
    |--------------------------------------------------------------------------
    |
    | The dashboard can dispatch jobs and run artisan commands. Because that is
    | a powerful capability (effectively remote code execution), it is OFF by
    | default — like the read-only posture of Horizon / Telescope / Pulse — and
    | governed by an allowlist when you opt in (VIGILANCE_CONTROL_ENABLED=true).
    | Set "jobs.mode" or "commands.mode" to:
    |
    |   - 'marker'   : only jobs implementing Vigilance\Contracts\Dispatchable
    |                  are allowed (opt-in per class). Commands fall back to 'list'.
    |   - 'list'     : only the explicitly listed classes/commands are allowed.
    |   - 'discover' : auto-discover every queued job in "paths" (jobs only) —
    |                  convenient, but exposes ALL of them; hide individual jobs
    |                  with the Vigilance\Contracts\ShouldNotBeDispatchedManually
    |                  marker (or the "deny" list).
    |   - 'all'      : allow everything (NOT recommended in production).
    |
    | "deny" always wins over any allow rule.
    |
    */

    'control' => [
        'enabled' => env('VIGILANCE_CONTROL_ENABLED', false),

        'jobs' => [
            'mode' => env('VIGILANCE_DISPATCH_JOBS_MODE', 'marker'),
            'paths' => [app_path('Jobs')],
            'allow' => [
                // App\Jobs\ProcessPodcast::class,
            ],
            'deny' => [],
        ],

        'commands' => [
            'mode' => env('VIGILANCE_RUN_COMMANDS_MODE', 'list'),
            'allow' => [
                // 'cache:clear',
                // 'app:sync-*',
            ],
            'deny' => array_merge([
                'queue:work',
                'queue:listen',
                'tinker',
                'migrate:fresh',
                'migrate:reset',
                'migrate:rollback',
                'db:wipe',
                'env:decrypt',
                'env:encrypt',
                'down',
                'up',
            ], Defaults::dangerousCommands()),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Parameter keys whose name matches any of these (case-insensitive) have
    | their value replaced with "[redacted]" before being stored. This guards
    | against accidentally persisting secrets in job properties or command
    | options such as --password=.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Silenced jobs
    |--------------------------------------------------------------------------
    |
    | Noisy but uninteresting jobs can be silenced so they're kept out of the
    | main Runs feed (they're still recorded — toggle "show silenced" to see
    | them). Match by job class (wildcards allowed) or by tag.
    |
    */

    'silence' => [
        'jobs' => [
            // App\Jobs\Heartbeat::class,
            // 'App\Jobs\Noisy*',
        ],
        'tags' => [
            // 'heartbeat',
        ],
    ],

    'redact' => [
        'password',
        'secret',
        'token',
        'authorization',
        'api_key',
        'apikey',
        'access_key',
        'private_key',
        'credit_card',
        'cvv',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention / pruning
    |--------------------------------------------------------------------------
    |
    | "vigilance:prune" deletes runs older than "days". Failed runs may be kept
    | longer via "failed_days". Metric snapshots are trimmed to "snapshots"
    | points per scope. Schedule the prune command to keep tables bounded.
    |
    */

    'retention' => [
        'days' => env('VIGILANCE_RETENTION_DAYS', 14),
        'failed_days' => env('VIGILANCE_RETENTION_FAILED_DAYS', 30),
        'snapshots' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Snapshots aggregate throughput / runtime / wait-time per job and per
    | queue. Schedule "vigilance:snapshot" at the interval below.
    |
    */

    'metrics' => [
        'enabled' => true,
        'snapshot_interval_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Vigilance can alert you when a queue is backing up (its estimated time to
    | clear exceeds "long_wait_seconds"). The check runs at "vigilance:snapshot"
    | time and is throttled per queue by "throttle_minutes".
    |
    | Set where alerts go straight from your .env via "mail" / "slack" below —
    | no service provider needed. "mail" takes a single address or a
    | comma-separated list. Calling Vigilance::routeMailNotificationsTo(...) /
    | routeSlackNotificationsTo(...) in a provider overrides these values.
    |
    */

    'notifications' => [
        'enabled' => true,

        // Single address or comma-separated list, e.g. "ops@example.com,cto@example.com".
        'mail' => env('VIGILANCE_ALERT_EMAILS'),
        // Slack / Discord / Microsoft Teams incoming-webhook URLs.
        'slack' => env('VIGILANCE_SLACK_WEBHOOK'),
        'discord' => env('VIGILANCE_DISCORD_WEBHOOK'),
        'teams' => env('VIGILANCE_TEAMS_WEBHOOK'),
        // Generic webhook(s) — each receives the alert as JSON (PagerDuty, Opsgenie, …).
        'webhooks' => env('VIGILANCE_ALERT_WEBHOOKS'),

        'long_wait_seconds' => env('VIGILANCE_LONG_WAIT_SECONDS', 60),
        'throttle_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts (rule-based)
    |--------------------------------------------------------------------------
    |
    | A rules engine, evaluated at "vigilance:snapshot" time, that alerts on more
    | than just queue backlog: failure-rate, exception spikes, slow-request rate,
    | and overdue/failed scheduled tasks. Each fired alert is throttled per key.
    | Route alerts with Vigilance::routeMailNotificationsTo(...) /
    | routeSlackNotificationsTo(...), or Vigilance::alertUsing(fn ($alert) => ...)
    | for a custom channel (a Notification, SMS, PagerDuty…). Add your own rules
    | (implementing Vigilance\Notifications\Contracts\AlertRule) under "custom".
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Issues (error tracking)
    |--------------------------------------------------------------------------
    |
    | Every reported exception — HTTP request errors and Vigilance::report()
    | surfaced exceptions — is grouped (Sentry-style fingerprint) into the
    | Issues inbox with a stack-trace sample and request context. Queue/command
    | failures are grouped automatically by the run capture. Set "sample_rate"
    | below 1.0 only for extreme exception volume; failures you care about are
    | usually worth keeping in full. "capture_request_input" is off by default
    | for privacy (input is redacted when on).
    |
    */

    'issues' => [
        'enabled' => true,
        'sample_rate' => (float) env('VIGILANCE_ISSUES_SAMPLE', 1.0),
        'capture_request_input' => (bool) env('VIGILANCE_ISSUES_CAPTURE_INPUT', false),
        'max_sample' => 8000,
        'except' => [
            // \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            // \Illuminate\Auth\AuthenticationException::class,
            // \Illuminate\Validation\ValidationException::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Real User Monitoring (RUM)
    |--------------------------------------------------------------------------
    |
    | Collects Core Web Vitals (LCP, INP, CLS, FCP, TTFB) and uncaught browser
    | errors from real visitors via a tiny beacon posted to a public, throttled
    | ingest endpoint. Off by default — it adds a public endpoint and a
    | front-end script. Browser errors land in the Issues inbox as source
    | "browser"; Web Vitals feed the Routes / performance views.
    |
    */

    'rum' => [
        'enabled' => (bool) env('VIGILANCE_RUM', false),
        'throttle' => env('VIGILANCE_RUM_THROTTLE', '120,1'),
        'capture_js_errors' => (bool) env('VIGILANCE_RUM_JS_ERRORS', true),
        // Symbolicate minified JS error stacks against uploaded source maps
        // (php artisan vigilance:sourcemaps) so browser errors are readable.
        'symbolicate' => (bool) env('VIGILANCE_RUM_SYMBOLICATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Level Objectives (SLOs)
    |--------------------------------------------------------------------------
    |
    | Track availability / latency objectives against an error budget, with a
    | short-window burn-rate alert. Each SLO is evaluated against the HTTP
    | request telemetry globally. "sli" is "success_rate" (1 − 5xx rate) or
    | "latency" (the Apdex score). Windows clamp to APM retention (≤ 7 days).
    | Define your own (off until you add one):
    |
    */

    'slos' => [
        // 'availability' => ['name' => 'API availability', 'sli' => 'success_rate', 'target' => 99.9, 'window_days' => 7],
        // 'page-speed'   => ['name' => 'Page speed',       'sli' => 'latency',      'target' => 95.0, 'window_days' => 7],
    ],

    'alerts' => [
        'enabled' => true,
        'throttle_minutes' => 15,

        // Persist fired alerts as incidents (open until the alert stops recurring
        // for "incident_resolve_after" throttle windows), tracking MTTR.
        'incidents' => true,
        'incident_resolve_after' => 3,

        // With incidents on, you're notified ONCE when an incident opens (or its
        // severity escalates), then it stays quiet until it resolves — so a
        // sustained condition (e.g. a breaching SLO) doesn't email you every
        // window. Set "renotify_minutes" > 0 to get a reminder every N minutes
        // while an incident is still open (0 = notify once). With incidents off,
        // alerts fall back to one notification per "throttle_minutes" window.
        'renotify_minutes' => (int) env('VIGILANCE_ALERT_RENOTIFY_MINUTES', 0),

        'rules' => [
            'queue_long_wait' => ['enabled' => true, 'seconds' => (int) env('VIGILANCE_LONG_WAIT_SECONDS', 60)],
            'error_rate' => ['enabled' => true, 'min_runs' => 20, 'percent' => 20],
            'exception_spike' => ['enabled' => true, 'count' => 50],
            'slow_request_rate' => ['enabled' => false, 'count' => 100],
            'scheduled_task_late' => ['enabled' => true],
            'slo_burn' => ['enabled' => true, 'burn_rate' => 2.0],

            // Fire a critical "bad deploy" alert when the latest deployment's
            // health regresses (see "release_health" below). Point a generic
            // webhook at it to trigger an automatic rollback.
            'deploy_regression' => ['enabled' => true],

            // Alert the first time a new error type (signature) is seen, and when
            // a previously-resolved issue regresses. "window_minutes" defaults to
            // the throttle window; "limit" caps how many fire per snapshot.
            'new_issue' => ['enabled' => true, 'limit' => 10],
            'issue_regression' => ['enabled' => true, 'limit' => 10],

            // Dynamic-baseline anomaly detection: fire when a metric's latest
            // bucket is "z" standard deviations above its rolling baseline over
            // "window" (1h | 6h | 24h). Guarded so it never alerts on noise:
            // needs "min_baseline" points, real variance, and a value past the
            // per-metric "min_value" floor. Override "metrics" to watch your own.
            'anomaly' => [
                'enabled' => true,
                'z' => 3.0,
                'window' => '6h',
                'min_baseline' => 12,
                'limit' => 8,
                // 'metrics' => [
                //     ['type' => 'request', 'aggregate' => 'avg', 'label' => 'latency', 'unit' => 'ms', 'min_value' => 150],
                // ],
            ],
        ],

        'custom' => [
            // \App\Vigilance\Alerts\DiskSpaceRule::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Release health (deploy-regression guard)
    |--------------------------------------------------------------------------
    |
    | After each deployment marker (vigilance:deploy), Vigilance compares the
    | request telemetry in the window after the deploy against the equal window
    | before it — error rate, latency, throughput — to decide whether the release
    | made things worse. A "regressed" verdict fires the "deploy_regression"
    | alert above and is shown on the Releases dashboard page.
    |
    */

    'release_health' => [
        'window_minutes' => 30,       // compare 30m after the deploy vs 30m before
        'min_requests' => 50,         // requests needed per window to judge
        'error_rate_increase' => 5.0, // percentage-point jump → regressed
        'latency_increase' => 0.5,    // +50% avg latency → regressed
    ],

    /*
    |--------------------------------------------------------------------------
    | APM (whole-app performance monitoring)
    |--------------------------------------------------------------------------
    |
    | Beyond jobs/commands, Vigilance can record application-wide telemetry
    | (slow requests, slow queries, cache, outgoing HTTP, exceptions, server
    | stats) into time-bucketed aggregates — like Laravel Pulse, but
    | driver-agnostic. It is engineered to be production-safe: recorders capture
    | cheaply, defer the heavy work, and flush AFTER the response is sent.
    |
    | Per-recorder "sample_rate" (0.0-1.0) and "threshold" (ms) keep overhead
    | and storage bounded at scale.
    |
    */

    'apm' => [
        'enabled' => env('VIGILANCE_APM_ENABLED', true),

        'ingest' => [
            // 'storage' (write-through), 'redis' (write-behind via a Redis stream,
            // drained by `vigilance:apm-work`), 'null' (drop), or a class-string
            // implementing Vigilance\Apm\Contracts\Ingest.
            'driver' => env('VIGILANCE_APM_INGEST', 'storage'),

            // Redis connection + stream used by the 'redis' driver.
            'connection' => env('VIGILANCE_APM_REDIS_CONNECTION', 'default'),
            'stream' => 'vigilance:apm:stream',
            'stream_max_length' => 10000,

            // Max entries buffered in a single request before a mid-request flush
            // is forced (a memory backstop for pathological requests).
            'buffer' => 5000,

            // Odds the buffer flush also trims old data (1-in-N), keeping the
            // trim cost off the hot path while bounding the tables over time.
            'trim' => ['lottery' => [1, 1000]],

            // Additional sinks the buffered telemetry is fanned out to alongside
            // the primary driver — the seam for forwarding the same Entry/Value
            // feed to an external APM (e.g. a future Laravel Nightwatch
            // exporter). Each must implement the Ingest contract; a failing
            // exporter can never break local capture.
            'exporters' => [
                // \App\Apm\NightwatchIngest::class,
            ],
        ],

        'storage' => [
            'driver' => 'database',
            'trim' => ['keep' => '7 days'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Recorders
        |--------------------------------------------------------------------------
        |
        | Each recorder captures one slice of telemetry. They all share the same
        | knobs: "enabled" toggles the recorder; "sample_rate" (0.0-1.0) keeps a
        | fraction of events; "threshold" (ms, or a [regex => ms] map) drops
        | anything faster; "ignore" is a list of regexes to skip; "groups"
        | collapses high-cardinality keys (e.g. /users/123 => /users/*).
        */

        'recorders' => [

            Servers::class => [
                'enabled' => env('VIGILANCE_APM_SERVERS', true),
                // 'name' => 'web-1',           // defaults to the hostname
                'interval' => 15,               // seconds between samples
                'directories' => [base_path()], // disks to report usage for
            ],

            SlowRequests::class => [
                'enabled' => env('VIGILANCE_APM_REQUESTS', true),
                'sample_rate' => (float) env('VIGILANCE_APM_REQUESTS_SAMPLE', 1),
                'threshold' => 1000,
                'ignore' => [
                    '#^/'.preg_quote((string) env('VIGILANCE_PATH', 'vigilance'), '#').'#', // the dashboard itself
                    '#^/livewire/#',
                    '#^/telescope#',
                    '#^/horizon#',
                ],
            ],

            // All requests (sampled), aggregated per route for the Routes page:
            // throughput, Apdex, error rate and latency percentiles. Distinct
            // from SlowRequests, which only keeps requests over its threshold.
            Requests::class => [
                'enabled' => env('VIGILANCE_APM_ROUTES', true),
                'sample_rate' => (float) env('VIGILANCE_APM_ROUTES_SAMPLE', 1),
                'apdex_threshold' => (int) env('VIGILANCE_APM_APDEX_MS', 300),
                'ignore' => [
                    '#^/'.preg_quote((string) env('VIGILANCE_PATH', 'vigilance'), '#').'#',
                    '#^/livewire/#',
                    '#^/telescope#',
                    '#^/horizon#',
                ],
            ],

            SlowQueries::class => [
                'enabled' => env('VIGILANCE_APM_QUERIES', true),
                'sample_rate' => (float) env('VIGILANCE_APM_QUERIES_SAMPLE', 1),
                'threshold' => 1000,
                'max_query_length' => 1000,
                'ignore' => [
                    '#vigilance_#', // never record reads of our own tables
                ],
            ],

            SlowOutgoingRequests::class => [
                'enabled' => env('VIGILANCE_APM_OUTGOING', true),
                'sample_rate' => (float) env('VIGILANCE_APM_OUTGOING_SAMPLE', 1),
                'threshold' => 1000,
                'groups' => [
                    // '#^https://api\.example\.com/v1/users/\d+$#' => 'https://api.example.com/v1/users/{user}',
                ],
            ],

            CacheInteractions::class => [
                'enabled' => env('VIGILANCE_APM_CACHE', true),
                'sample_rate' => (float) env('VIGILANCE_APM_CACHE_SAMPLE', 1),
                'ignore' => [
                    '#^vigilance:#',     // our own cache traffic
                    '#^illuminate:#',
                ],
                'groups' => [
                    // '#^job-exceptions:.*#' => 'job-exceptions:*',
                ],
            ],

            Exceptions::class => [
                'enabled' => env('VIGILANCE_APM_EXCEPTIONS', true),
                'sample_rate' => (float) env('VIGILANCE_APM_EXCEPTIONS_SAMPLE', 1),
                'ignore' => [
                    // '#^Symfony\\\\.*HttpException$#',
                ],
            ],

            UserRequests::class => [
                'enabled' => env('VIGILANCE_APM_USER_REQUESTS', true),
                'sample_rate' => (float) env('VIGILANCE_APM_USER_REQUESTS_SAMPLE', 1),
            ],

            Queues::class => [
                'enabled' => env('VIGILANCE_APM_QUEUES', true),
                'sample_rate' => (float) env('VIGILANCE_APM_QUEUES_SAMPLE', 1),
            ],

            SlowJobs::class => [
                'enabled' => env('VIGILANCE_APM_SLOW_JOBS', true),
                'sample_rate' => (float) env('VIGILANCE_APM_SLOW_JOBS_SAMPLE', 1),
                'threshold' => 1000,
            ],

            UserJobs::class => [
                'enabled' => env('VIGILANCE_APM_USER_JOBS', true),
                'sample_rate' => (float) env('VIGILANCE_APM_USER_JOBS_SAMPLE', 1),
            ],

            Mail::class => [
                'enabled' => env('VIGILANCE_APM_MAIL', true),
                'sample_rate' => (float) env('VIGILANCE_APM_MAIL_SAMPLE', 1),
                'groups' => [
                    // '#^.+@(.+)$#' => '*@$1', // collapse to recipient domain
                ],
            ],

            Notifications::class => [
                'enabled' => env('VIGILANCE_APM_NOTIFICATIONS', true),
                'sample_rate' => (float) env('VIGILANCE_APM_NOTIFICATIONS_SAMPLE', 1),
            ],

            Logs::class => [
                'enabled' => env('VIGILANCE_APM_LOGS', true),
                'sample_rate' => (float) env('VIGILANCE_APM_LOGS_SAMPLE', 1),
                // Only these levels become APM volume (widen if you want info/debug).
                'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
            ],

        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Uptime monitoring
    |--------------------------------------------------------------------------
    |
    | Schedule "vigilance:health" to ping these URLs and record availability +
    | response time as APM metrics (shown on the APM "Uptime" card). Off until
    | you list URLs.
    |
    */

    'uptime' => [
        'enabled' => env('VIGILANCE_UPTIME', false),
        'timeout' => (int) env('VIGILANCE_UPTIME_TIMEOUT', 5),
        'urls' => [
            // 'https://your-app.test/up',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing (per-request / per-job span timelines)
    |--------------------------------------------------------------------------
    |
    | The deep-dive layer: a waterfall of every query / cache op / outgoing call
    | inside a single request or job. Because that is far heavier than the rolled
    | APM aggregates, it is OFF by default and engineered to stay cheap when on:
    |
    |  - Spans are collected in a cheap in-memory buffer during the request and
    |    flushed AFTER the response is sent (zero request latency).
    |  - A trace is persisted only when it is "interesting": slow (>=
    |    "slow_threshold" ms), errored, or head-sampled ("sample_rate"). Everything
    |    else is discarded — so at millions of queries you store a tiny fraction.
    |  - "max_spans" caps each trace so a pathological N+1 request can't blow up
    |    memory or storage (the overflow becomes a dropped-span counter).
    |  - Point "storage.connection" at a dedicated connection to keep trace writes
    |    off your primary database entirely.
    |
    | Raise "sample_rate" (0.0-1.0, or a [type => rate] map) to also keep a
    | baseline of normal traces; keep it at 0 to store only slow + failed ones.
    |
    */

    'tracing' => [
        'enabled' => env('VIGILANCE_TRACING', false),

        'sample_rate' => env('VIGILANCE_TRACING_SAMPLE', 0),

        'slow_threshold' => (int) env('VIGILANCE_TRACING_SLOW', 1000),

        'max_spans' => 1000,

        'max_attribute_length' => 2000,

        // Which root types to trace.
        'capture' => [
            'requests' => true,
            'jobs' => true,
            'commands' => false,
        ],

        // Which child spans to record.
        'spans' => [
            'queries' => true,
            'cache' => true,
            'http' => true,
            'redis' => true,
            'mail' => true,
            'notifications' => true,
        ],

        // Flag a trace as a likely N+1 when one identical query shape runs at
        // least this many times in a single request/job.
        'n_plus_one_threshold' => 10,

        // Routes / requests never traced (regex against the path).
        'ignore' => [
            '#^/'.preg_quote((string) env('VIGILANCE_PATH', 'vigilance'), '#').'#',
            '#^/livewire/#',
            '#^/_debugbar#',
            '#^/telescope#',
            '#^/horizon#',
        ],

        'retention' => env('VIGILANCE_TRACING_RETENTION', '72 hours'),

        // Odds a trace persist also trims expired traces (1-in-N).
        'trim' => ['lottery' => [1, 200]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log explorer (searchable app logs, correlated to traces)
    |--------------------------------------------------------------------------
    |
    | Captures application log records (Log::info(), Log::error(), …) into a
    | searchable explorer and correlates each line to the in-flight trace, so
    | you can pivot from a slow or failed trace straight to the logs it emitted.
    | OFF by default — it writes a row per qualifying log line. Like tracing, it
    | is engineered to stay cheap: records are buffered in memory and flushed in
    | one batched insert AFTER the response is sent (zero request latency).
    |
    |  - "level" is the minimum severity captured (debug < info < notice <
    |    warning < error < critical < alert < emergency). Raise it to "warning"
    |    or "error" to keep only the lines worth keeping at high volume.
    |  - "sample_rate" (0.0-1.0) keeps a fraction of qualifying lines.
    |  - "ignore" is a list of regexes matched against the message.
    |  - Point "storage.connection" at a dedicated connection to keep log writes
    |    off your primary database.
    |
    */

    'logs' => [
        'enabled' => (bool) env('VIGILANCE_LOGS', false),

        'level' => env('VIGILANCE_LOGS_LEVEL', 'debug'),

        'sample_rate' => (float) env('VIGILANCE_LOGS_SAMPLE', 1.0),

        // Regexes matched against the log message; matches are never recorded.
        'ignore' => [
            // '#^Telescope#',
        ],

        'max_message_length' => 8000,
        'max_context_length' => 8000,

        // Max records buffered in a single request before a flush is forced.
        'max_buffer' => 500,

        'retention' => env('VIGILANCE_LOGS_RETENTION', '72 hours'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker supervision (the "vigilance:supervise" runtime)
    |--------------------------------------------------------------------------
    |
    | Vigilance can run and auto-scale your queue workers itself — a single
    | "vigilance:supervise" process replaces hand-rolled "queue:work" calls and,
    | unlike Horizon, works on ANY queue driver (database, redis, sqs,
    | beanstalkd). Define one or more supervisors per environment; each manages
    | a pool of worker processes for its connection/queues.
    |
    | balance:
    |   - 'auto'   : distribute processes across queues by load (size/time)
    |   - 'simple' : split max_processes evenly across queues
    |   - false    : a single pool sized to the backlog (min..max)
    |
    | Control is delivered through a DB/cache flag polled by the supervisor
    | loop (so pause/restart/terminate work even where POSIX signals don't,
    | e.g. Windows); signals are used as a fast path when available.
    |
    */

    'supervision' => [
        // Seconds a supervisor/worker may miss its heartbeat before being
        // considered dead and pruned from the dashboard.
        'heartbeat_expire' => 30,

        // Cache store used for pause/restart/terminate flags (null = default).
        'cache_store' => null,
    ],

    'defaults' => [
        'connection' => env('VIGILANCE_SUPERVISOR_CONNECTION', 'database'),
        'queue' => ['default'],
        'balance' => 'auto',
        'auto_scaling_strategy' => 'time', // 'time' | 'size'
        'min_processes' => 1,
        'max_processes' => 10,
        'balance_max_shift' => 1,
        'balance_cooldown' => 3,
        'max_time' => 0,
        'max_jobs' => 0,
        'memory' => 128,
        'tries' => 1,
        'timeout' => 60,
        'sleep' => 3,
        'nice' => 0,
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'max_processes' => 10,
                'balance' => 'auto',
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'max_processes' => 3,
            ],
        ],
    ],
];
