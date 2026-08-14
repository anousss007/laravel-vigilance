<?php

namespace Workbench\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Vigilance\Apm\Apm;

/**
 * Fills every Vigilance table with a plausible week of traffic for a mid-sized
 * app, so the visual harness photographs pages that are *full* — long names,
 * stack traces, wide numbers, French relative dates. An empty dashboard hides
 * exactly the layout faults (overflow, collisions, unstyled cells) this exists
 * to catch, so the data is deliberately shaped to stress the widest cell in
 * every column.
 *
 * Deterministic: the RNG is seeded, so two runs produce identical screenshots
 * and a visual diff only ever reflects a code change.
 */
class DatabaseSeeder extends Seeder
{
    protected CarbonImmutable $now;

    /** @var list<string> */
    protected array $jobs = [
        'App\Jobs\ExpireReservationJob',
        'App\Jobs\SendConfirmationNudgesJob',
        'App\Jobs\BookingDraftSweepJob',
        'App\Jobs\SendReservationReminderJob',
        'App\Jobs\SendReturnReminderJob',
        'App\Jobs\ComputeAgencyResponsivenessJob',
        'App\Jobs\PruneStripeWebhookEventsJob',
        'App\Jobs\SendFleetDeadlineRemindersJob',
        'App\Jobs\Telegram\SendTelegramMessage',
        'App\Jobs\BroadcastEvent',
    ];

    /** @var list<string> */
    protected array $routes = [
        'GET /api/v1/agency/vehicles/{vehicle}/calendar',
        'GET /{locale}/vehicles/{vehicle}',
        'POST /api/v1/bookings',
        'GET /{locale}/search',
        'GET /{locale}/teaser',
        'POST /webhooks/stripe',
        'GET /api/v1/agency/reservations',
        'PATCH /api/v1/agency/vehicles/{vehicle}',
    ];

    public function run(): void
    {
        $this->now = CarbonImmutable::now();

        mt_srand(20260814);

        $this->seedRuns();
        $this->seedIssues();
        $this->seedSchedule();
        $this->seedWorkers();
        $this->seedIncidents();
        $this->seedSnapshots();
        $this->seedTraces();
        $this->seedLogs();
        $this->seedDeployments();
        $this->seedAudit();
        $this->seedSuppressions();
        $this->seedFeedback();
        $this->seedQueueBacklog();
        $this->seedBatches();
        $this->seedApm();
    }

    /** A live backlog in Laravel's `jobs` table for the Pending page. */
    protected function seedQueueBacklog(): void
    {
        $rows = [];

        for ($i = 0; $i < 18; $i++) {
            $name = $this->pick($this->jobs);
            $delayed = mt_rand(1, 5) === 1;

            $rows[] = [
                'queue' => $this->pick(['default', 'mail', 'maintenance']),
                'payload' => (string) json_encode([
                    'uuid' => $this->uuid('pending', $i),
                    'displayName' => $name,
                    'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                    'data' => ['commandName' => $name],
                ]),
                'attempts' => mt_rand(0, 2),
                'reserved_at' => mt_rand(1, 6) === 1 ? $this->now->getTimestamp() : null,
                'available_at' => $delayed ? $this->now->addMinutes(mt_rand(1, 90))->getTimestamp() : $this->now->subMinutes(mt_rand(1, 30))->getTimestamp(),
                'created_at' => $this->now->subMinutes(mt_rand(1, 40))->getTimestamp(),
            ];
        }

        $this->table('jobs')->insert($rows);
    }

    /** Laravel's own batch table, so the Batches page has progress to draw. */
    protected function seedBatches(): void
    {
        $batches = [
            ['Nightly agency re-index', 500, 0, 0, true],
            ['Send August invoices', 320, 74, 3, false],
            ['Backfill vehicle thumbnails', 1200, 640, 0, false],
            ['Reprice summer season', 210, 0, 12, true],
        ];

        foreach ($batches as $i => [$name, $total, $pending, $failed, $finished]) {
            $created = $this->now->subHours(($i * 5) + 1);

            $this->table('job_batches')->insert([
                'id' => $this->uuid('batch', $i + 1),
                'name' => $name,
                'total_jobs' => $total,
                'pending_jobs' => $pending,
                'failed_jobs' => $failed,
                'failed_job_ids' => json_encode($failed > 0 ? [$this->uuid('failed', $i)] : []),
                // Laravel unserializes this column; an empty string yields false
                // and BatchFactory then type-errors, which the page reports as
                // "batching isn't set up".
                'options' => serialize([]),
                'cancelled_at' => null,
                'created_at' => $created->getTimestamp(),
                'finished_at' => $finished ? $created->addMinutes(12)->getTimestamp() : null,
            ]);
        }
    }

    protected function table(string $name): Builder
    {
        return DB::table($name);
    }

    protected function pick(array $values): mixed
    {
        return $values[mt_rand(0, count($values) - 1)];
    }

    /**
     * UUID-shaped but derived from a counter: the visual harness has to be able
     * to open /traces/{id} and /runs/{id} by URL, which a random uuid would make
     * impossible, and the width of a real UUID is part of what's being tested.
     */
    protected function uuid(string $prefix, int $n): string
    {
        $hex = substr(hash('sha256', $prefix.$n), 0, 32);

        return sprintf(
            '%s-%s-7%s-8%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 3),
            substr($hex, 16, 3), substr($hex, 20, 12),
        );
    }

    /** A week of job/command/schedule runs, weighted towards recent activity. */
    protected function seedRuns(): void
    {
        $rows = [];
        $tags = [];

        for ($i = 0; $i < 900; $i++) {
            // Squaring biases the sample towards "just now", which is what a
            // live dashboard looks like — and keeps the first page of every
            // list densely populated.
            $agoMinutes = (int) round(10080 * (mt_rand(0, 1000) / 1000) ** 2);
            $started = $this->now->subMinutes($agoMinutes);

            $type = mt_rand(1, 100) <= 88 ? 'job' : (mt_rand(0, 1) ? 'command' : 'schedule');
            $name = $type === 'job' ? $this->pick($this->jobs) : $this->pick([
                'queue:prune-batches', 'vigilance:prune', 'backup:run --only-db', 'sitemap:generate',
            ]);

            $roll = mt_rand(1, 100);
            $status = match (true) {
                $roll <= 92 => 'succeeded',
                $roll <= 96 => 'failed',
                $roll <= 98 => 'running',
                default => 'queued',
            };

            $duration = mt_rand(60, 11000);
            $failed = $status === 'failed';

            $rows[] = [
                'id' => $i + 1,
                'uuid' => $this->uuid('run', $i + 1),
                'type' => $type,
                'name' => $name,
                'display_name' => $name,
                'status' => $status,
                // The Pending page lists the connections it has seen runs on,
                // and only the database driver's backlog is browsable — so most
                // runs are recorded on it, with a redis node in the mix to
                // exercise the "not browsable" branch too.
                'connection_name' => mt_rand(1, 6) === 1 ? 'redis' : 'database',
                'queue' => $this->pick(['default', 'mail', 'maintenance', 'broadcast']),
                'attempt' => $failed ? mt_rand(1, 3) : 1,
                'parameters' => json_encode([
                    'reservation' => ['id' => mt_rand(1000, 9999), 'agency' => 'Yahya Cars Marrakech'],
                    'locale' => $this->pick(['fr', 'en', 'ar']),
                ]),
                'tags' => json_encode(['App\Models\Reservation:'.mt_rand(1000, 9999)]),
                'output' => $type === 'command' ? "Pruned 128 rows.\nDone in 412ms." : null,
                'exception_class' => $failed ? 'App\Exceptions\EmailDeliveryFailed' : null,
                'exception_message' => $failed ? 'Resend email.bounced — to: contact@yahyacars.ma' : null,
                'exception' => $failed ? $this->stackTrace() : null,
                'via' => mt_rand(1, 10) === 1 ? 'manual' : 'auto',
                'caused_by' => mt_rand(1, 10) === 1 ? 'anas@remix-it.be' : null,
                'memory_peak' => mt_rand(58, 96) * 1024 * 1024,
                'cpu_time_ms' => mt_rand(4, 700),
                'queued_at' => $started->subSeconds(mt_rand(0, 40)),
                'started_at' => $started,
                'finished_at' => in_array($status, ['succeeded', 'failed'], true) ? $started->addMilliseconds($duration) : null,
                'wait_ms' => mt_rand(0, 40000),
                'duration_ms' => in_array($status, ['succeeded', 'failed'], true) ? $duration : null,
                'created_at' => $started,
                'updated_at' => $started,
            ];

            $tags[] = [
                'run_id' => $i + 1,
                'tag' => $this->pick([
                    'App\Models\Reservation:019fc5b9-9391-7372-b88a-3176a28dbeec',
                    'App\Models\Guide:019fc5b9-93ab-7112-8d60-0f467e53a6c1',
                    'App\Models\Prospect:5b5ffe7e-0713-42ad-a2ba-d6c43931de4a',
                    'agency:yahya-cars',
                    'billing',
                ]),
                'created_at' => $started,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->table('vigilance_runs')->insert($chunk);
        }

        $this->table('vigilance_run_tags')->insert($tags);

        $this->table('vigilance_monitored_tags')->insert([
            ['tag' => 'billing', 'created_at' => $this->now->subDays(4)],
            ['tag' => 'agency:yahya-cars', 'created_at' => $this->now->subDays(2)],
        ]);
    }

    /** Issue groups across every source, status and priority the UI renders. */
    protected function seedIssues(): void
    {
        $issues = [
            ['webhooks.resend', 'App\Exceptions\EmailDeliveryFailed', 'request', 'Resend email.bounced — to: contact@yahyacars.ma (The recipient mailbox is full)', 412, 'normal', null, null],
            ['/en/teaser', 'JavaScriptError', 'browser', 'Unhandled rejection: Failed to fetch dynamically imported module: https://yahyacars.ma/build/assets/teaser-CJd8s.js', 87, 'high', null, null],
            ['api.agency.vehicles.calendar', 'TypeError', 'request', 'App\Services\Pricing\PricingService::resolveDailyRate(): Argument #2 ($season) must be of type App\Enums\Season, null given', 34, 'critical', null, null],
            ['App\Jobs\SendTelegramMessage', 'Illuminate\Http\Client\ConnectionException', 'job', 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received', 21, 'normal', 'anas@remix-it.be', null],
            ['reservations.store', 'Illuminate\Database\QueryException', 'request', 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key reservations_reference_unique', 9, 'high', null, 'acknowledged'],
            ['sitemap:generate', 'ErrorException', 'command', 'Undefined array key "canonical"', 4, 'low', null, 'resolved'],
            ['/fr/recherche', 'JavaScriptError', 'browser', "Cannot read properties of undefined (reading 'coords')", 3, 'normal', null, 'muted'],
        ];

        $rows = [];

        foreach ($issues as $i => [$name, $class, $source, $message, $count, $priority, $assignee, $state]) {
            $last = $this->now->subHours(mt_rand(1, 14 * 24));

            $rows[] = [
                'id' => $i + 1,
                'signature' => hash('sha256', $name.$class),
                'type' => in_array($source, ['request', 'browser'], true) ? 'request' : $source,
                'source' => $source,
                'first_release' => 'v2.4.'.mt_rand(0, 9),
                'name' => $name,
                'exception_class' => $class,
                'message' => $message,
                'sample' => $this->stackTrace(),
                'context' => json_encode(['url' => 'https://yahyacars.ma/'.$name, 'method' => 'POST', 'user' => 'anas@remix-it.be']),
                'occurrences' => $count,
                'priority' => $priority,
                'assignee' => $assignee,
                'acknowledged_at' => $state === 'acknowledged' ? $this->now->subHours(3) : null,
                'resolved_at' => $state === 'resolved' ? $this->now->subDays(1) : null,
                'muted_until' => $state === 'muted' ? $this->now->addDays(2) : null,
                'first_seen_at' => $this->now->subDays(mt_rand(8, 40)),
                'last_seen_at' => $last,
                'created_at' => $this->now->subDays(mt_rand(8, 40)),
                'updated_at' => $last,
            ];
        }

        $this->table('vigilance_failure_groups')->insert($rows);

        // Link the failed runs to a group so the issue detail page lists them.
        $this->table('vigilance_runs')->where('status', 'failed')->update(['failure_group_id' => 1]);
    }

    protected function seedSchedule(): void
    {
        $tasks = [
            ['queue:prune-batches', '0 3 * * *', 'succeeded'],
            ['vigilance:prune --hours=168', '0 * * * *', 'succeeded'],
            ['backup:run --only-db', '30 2 * * *', 'failed'],
            ['App\Jobs\ComputeAgencyResponsivenessJob', '*/15 * * * *', 'succeeded'],
            ['sitemap:generate', '0 4 * * 1', 'skipped'],
            ['telescope:prune --hours=48', '0 5 * * *', 'succeeded'],
        ];

        $rows = [];

        foreach ($tasks as [$name, $cron, $state]) {
            $started = $this->now->subMinutes(mt_rand(5, 900));

            $rows[] = [
                'name' => $name,
                'type' => str_starts_with($name, 'App\\') ? 'job' : 'command',
                'cron_expression' => $cron,
                'timezone' => 'Africa/Casablanca',
                'grace_time_minutes' => 5,
                'monitored' => true,
                'last_started_at' => $started,
                'last_finished_at' => $state === 'skipped' ? null : $started->addSeconds(mt_rand(1, 40)),
                'last_failed_at' => $state === 'failed' ? $started : null,
                'last_skipped_at' => $state === 'skipped' ? $started : null,
                'last_duration_ms' => $state === 'skipped' ? null : mt_rand(200, 42000),
                'created_at' => $this->now->subDays(30),
                'updated_at' => $started,
            ];
        }

        $this->table('vigilance_scheduled_tasks')->insert($rows);
    }

    protected function seedWorkers(): void
    {
        $supervisors = [
            ['vigilance-supervisor-1', 'web-01.yahyacars.ma', 'running', ['default', 'mail'], 8],
            ['vigilance-supervisor-1', 'worker-02.yahyacars.ma', 'running', ['maintenance'], 4],
            ['vigilance-supervisor-2', 'worker-03.yahyacars.ma', 'paused', ['broadcast'], 2],
        ];

        $workers = [];

        foreach ($supervisors as $i => [$name, $host, $status, $queues, $processes]) {
            $this->table('vigilance_supervisors')->insert([
                'name' => $name,
                'master' => 'vigilance-master',
                'host' => $host,
                'pid' => 4000 + $i,
                'status' => $status,
                'connection' => 'redis',
                'queues' => implode(',', $queues),
                'balance' => 'auto',
                'processes' => $processes,
                'pools' => json_encode([['queue' => $queues[0], 'processes' => $processes]]),
                'options' => json_encode(['timeout' => 60, 'tries' => 3, 'memory' => 128]),
                'last_heartbeat_at' => $this->now->subSeconds(mt_rand(1, 20)),
                'created_at' => $this->now->subDays(9),
                'updated_at' => $this->now,
            ]);

            for ($p = 0; $p < $processes; $p++) {
                $workers[] = [
                    'supervisor' => $name,
                    'host' => $host,
                    'pid' => 40000 + ($i * 100) + $p,
                    'connection' => 'redis',
                    'queue' => $queues[$p % count($queues)],
                    'status' => $status === 'paused' ? 'paused' : 'running',
                    'last_heartbeat_at' => $this->now->subSeconds(mt_rand(1, 25)),
                    'created_at' => $this->now->subDays(9),
                    'updated_at' => $this->now,
                ];
            }
        }

        $this->table('vigilance_workers')->insert($workers);
    }

    protected function seedIncidents(): void
    {
        $incidents = [
            ['queue_long_wait:mail', 'Mail queue waiting 3m 12s', 'critical', 'open', 14],
            ['failure_rate:App\Jobs\SendTelegramMessage', 'SendTelegramMessage failing 38% of runs', 'warning', 'open', 6],
            ['slo_burn:availability', 'API availability burning error budget 4.1× faster than sustainable', 'warning', 'resolved', 3],
            ['schedule_missed:backup:run', 'Scheduled backup:run --only-db missed its window', 'critical', 'resolved', 1],
        ];

        foreach ($incidents as $i => [$key, $title, $level, $status, $occurrences]) {
            $opened = $this->now->subHours(mt_rand(2, 70));

            $this->table('vigilance_incidents')->insert([
                'key' => $key,
                'title' => $title,
                'message' => 'Detected by the '.explode(':', $key)[0].' alert rule.',
                'level' => $level,
                'status' => $status,
                'occurrences' => $occurrences,
                'opened_at' => $opened,
                'last_seen_at' => $status === 'open' ? $this->now->subMinutes(mt_rand(1, 30)) : $opened->addHours(2),
                'resolved_at' => $status === 'resolved' ? $opened->addHours(mt_rand(1, 5)) : null,
                'created_at' => $opened,
                'updated_at' => $this->now,
            ]);
        }
    }

    /** Throughput/failure/runtime series behind the Metrics and Overview charts. */
    protected function seedSnapshots(): void
    {
        $rows = [];

        foreach (['queue:default', 'queue:mail', 'queue:maintenance', 'job:App\Jobs\ExpireReservationJob'] as $scope) {
            [$type, $name] = explode(':', $scope, 2);

            for ($m = 7 * 24 * 60; $m >= 0; $m -= 30) {
                $rows[] = [
                    'scope_type' => $type,
                    'scope' => $name,
                    'throughput' => mt_rand(20, 260),
                    'failures' => mt_rand(0, 6),
                    'runtime_avg_ms' => mt_rand(120, 2400),
                    'wait_avg_ms' => mt_rand(10, 9000),
                    'measured_at' => $this->now->subMinutes($m),
                ];
            }
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            $this->table('vigilance_metric_snapshots')->insert($chunk);
        }
    }

    protected function seedTraces(): void
    {
        $spans = [];

        for ($i = 0; $i < 60; $i++) {
            $id = $this->uuid('trace', $i + 1);
            $started = $this->now->subMinutes((int) round(1440 * (mt_rand(0, 1000) / 1000) ** 2));
            $duration = mt_rand(80, 4200);
            $name = mt_rand(1, 4) === 1 ? $this->pick($this->jobs) : $this->pick($this->routes);

            $this->table('vigilance_traces')->insert([
                'id' => $id,
                'type' => str_starts_with($name, 'App\\') ? 'job' : 'request',
                'name' => $name,
                'status' => mt_rand(1, 12) === 1 ? 'error' : 'ok',
                'duration_ms' => $duration,
                'span_count' => 0,
                'dropped_spans' => 0,
                'user_id' => mt_rand(1, 3) === 1 ? 'anas@remix-it.be' : null,
                'started_at' => $started->getTimestamp(),
                'attributes' => json_encode(['http.method' => 'GET', 'http.status' => 200, 'release' => 'v2.4.9']),
                'created_at' => $started,
            ]);

            $cursor = 0;
            $count = mt_rand(6, 18);

            for ($s = 0; $s < $count; $s++) {
                $spanDuration = mt_rand(500, (int) max(1000, $duration * 900 / $count));

                // Types must be the ones the tracer really emits (query, redis,
                // cache, http, mail, notification, exception) — anything else
                // falls through the timeline's colour map and photographs as an
                // undifferentiated grey bar.
                [$spanType, $label] = $this->pick([
                    ['query', 'select * from `reservations` where `agency_id` = ? and `starts_at` between ? and ? order by `starts_at` asc limit 50'],
                    ['query', 'select * from `vehicles` where `vehicles`.`id` = ? limit 1'],
                    ['http', 'POST https://api.stripe.com/v1/payment_intents'],
                    ['redis', 'GET vigilance:pricing:019fc5b9'],
                    ['cache', 'miss availability:019fc5b9-9391-7372-b88a-3176a28dbeec'],
                    ['mail', 'mail sent'],
                    ['notification', 'notify mail'],
                ]);

                $spans[] = [
                    'trace_id' => $id,
                    'parent_id' => null,
                    'type' => $spanType,
                    'label' => $label,
                    'start_us' => $cursor,
                    'duration_us' => $spanDuration,
                    'attributes' => json_encode(['rows' => mt_rand(1, 400)]),
                ];

                $cursor += $spanDuration;
            }

            $this->table('vigilance_traces')->where('id', $id)->update(['span_count' => $count]);
        }

        foreach (array_chunk($spans, 300) as $chunk) {
            $this->table('vigilance_spans')->insert($chunk);
        }
    }

    protected function seedLogs(): void
    {
        $levels = [
            ['error', 400, 'Stripe webhook signature verification failed for event evt_3QhK2mB4rC8dLp1x'],
            ['warning', 300, 'Pricing fallback used: no season configured for 2026-08-14 on vehicle 019fb352-0a76-7158'],
            ['info', 200, 'Reservation R-20260814-0042 confirmed for contact@yahyacars.ma'],
            ['debug', 100, 'Cache miss vigilance:availability:019fc5b9-9391-7372-b88a-3176a28dbeec'],
            ['critical', 500, 'Queue worker on worker-03.yahyacars.ma stopped responding to heartbeats'],
        ];

        $rows = [];

        for ($i = 0; $i < 400; $i++) {
            [$level, $value, $message] = $this->pick($levels);
            $at = $this->now->subMinutes((int) round(1440 * (mt_rand(0, 1000) / 1000) ** 2));

            $rows[] = [
                'level' => $level,
                'level_value' => $value,
                'message' => $message,
                'context' => json_encode(['agency' => 'yahya-cars', 'ip' => '196.64.221.'.mt_rand(2, 250)]),
                'channel' => $this->pick(['stack', 'single', 'stderr']),
                'trace_id' => null,
                'logged_at' => $at->getTimestamp(),
                'created_at' => $at,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->table('vigilance_logs')->insert($chunk);
        }
    }

    protected function seedDeployments(): void
    {
        foreach ([['v2.4.9', 4], ['v2.4.8', 30], ['v2.4.7', 76], ['v2.4.6', 121]] as $i => [$version, $hoursAgo]) {
            $this->table('vigilance_deployments')->insert([
                'version' => $version,
                'commit' => substr(hash('sha1', $version), 0, 40),
                'environment' => 'production',
                'notes' => $i === 0 ? 'Pricing seasons + agency calendar rewrite' : 'Routine deploy',
                'deployed_at' => $this->now->subHours($hoursAgo),
                'created_at' => $this->now->subHours($hoursAgo),
            ]);
        }
    }

    protected function seedAudit(): void
    {
        foreach (['retry', 'dispatch', 'resolve', 'acknowledge', 'pause', 'command'] as $i => $action) {
            $this->table('vigilance_audit')->insert([
                'user' => 'anas@remix-it.be',
                'action' => $action,
                'subject' => $this->pick($this->jobs),
                'run_id' => mt_rand(1, 900),
                'meta' => json_encode(['ip' => '196.64.221.14']),
                'created_at' => $this->now->subHours($i * 3 + 1),
            ]);
        }
    }

    protected function seedSuppressions(): void
    {
        $this->table('vigilance_suppressions')->insert([
            [
                'scope' => 'route',
                'pattern' => 'GET /{locale}/teaser',
                'action' => 'ignore',
                'replacement' => null,
                'created_by' => 'anas@remix-it.be',
                'note' => 'Marketing teaser, not worth alerting on',
                'expires_at' => null,
                'created_at' => $this->now->subDays(3),
                'updated_at' => $this->now->subDays(3),
            ],
        ]);
    }

    protected function seedFeedback(): void
    {
        $this->table('vigilance_user_feedback')->insert([
            [
                'message' => 'The calendar shows the vehicle as available but booking it returns a 500. Happens every time on mobile Safari.',
                'email' => 'contact@yahyacars.ma',
                'name' => 'Yahya Cars',
                'url' => 'https://yahyacars.ma/fr/vehicles/019fb352-0a76-7158-b06a-4f35ee56da3b',
                'trace_id' => null,
                'user' => null,
                'created_at' => $this->now->subHours(6),
            ],
        ]);
    }

    /**
     * APM data goes through the real recorder API rather than hand-written
     * aggregate rows: the bucket/period maths is the ingest's job, and seeding
     * it by hand would photograph a layout fed by data the app can't produce.
     */
    protected function seedApm(): void
    {
        $apm = app(Apm::class);

        $apm->set('system', 'web-01-yahyacars-ma', (string) json_encode([
            'name' => 'web-01.yahyacars.ma',
            'cpu' => 34,
            'memory_used' => 5321,
            'memory_total' => 16384,
            'storage' => [['directory' => '/', 'used' => 82, 'total' => 200]],
            'timestamp' => $this->now->getTimestamp(),
        ]));

        $apm->set('system', 'worker-02-yahyacars-ma', (string) json_encode([
            'name' => 'worker-02.yahyacars.ma',
            'cpu' => 71,
            'memory_used' => 11922,
            'memory_total' => 16384,
            'storage' => [['directory' => '/', 'used' => 140, 'total' => 200]],
            'timestamp' => $this->now->getTimestamp(),
        ]));

        // 24h of per-minute-ish samples, dense enough for the 1h and 24h windows.
        for ($m = 24 * 60; $m >= 0; $m -= 3) {
            $ts = $this->now->subMinutes($m)->getTimestamp();

            foreach ([
                ['vigilance-supervisor-1', 'default', 8],
                ['vigilance-supervisor-1', 'mail', 4],
                ['vigilance-supervisor-2', 'broadcast', 2],
            ] as [$supervisor, $pool, $size]) {
                $apm->record('workers', (string) json_encode([$supervisor, $pool]), max(0, $size + mt_rand(-2, 2)), $ts)
                    ->avg()->max()->onlyBuckets();
            }

            foreach (['web-01-yahyacars-ma' => 34, 'worker-02-yahyacars-ma' => 71] as $slug => $base) {
                $apm->record('cpu', $slug, max(1, $base + mt_rand(-18, 18)), $ts)->avg()->onlyBuckets();
                $apm->record('memory', $slug, mt_rand(4000, 12000), $ts)->avg()->onlyBuckets();
            }

            foreach ($this->routes as $route) {
                $key = (string) json_encode([explode(' ', $route)[0], explode(' ', $route)[1]]);
                $duration = mt_rand(30, 2600);

                $apm->record('request', $key, $duration, $ts)->count()->avg()->max();
                $apm->record('request_apdex', $key, $duration < 300 ? 100 : ($duration < 1200 ? 50 : 0), $ts)->avg();
                $apm->record('request_queries', $key, mt_rand(3, 140), $ts)->count()->avg()->max();
                $apm->record('request_memory', $key, mt_rand(2000, 42000), $ts)->count()->avg()->max();
                $apm->record('request_db_ms', $key, mt_rand(2, 900), $ts)->avg()->max();
                $apm->record('request_models', $key, mt_rand(1, 800), $ts)->avg()->max();

                if (mt_rand(1, 14) === 1) {
                    $apm->record('request_error', $key, 500, $ts)->count();
                }

                if ($duration > 1800) {
                    $apm->record('slow_request', $key, $duration, $ts)->max()->count();
                }
            }

            if ($m % 12 === 0) {
                // Key shapes mirror the recorders exactly (SlowQueries and
                // Exceptions use named keys, the request/outgoing recorders use
                // a [method, target] pair) — seed them wrong and the cards
                // render their fallback branch, which is not what ships.
                $apm->record('slow_query', (string) json_encode([
                    'sql' => 'select * from `reservations` where `agency_id` = ? and `starts_at` between ? and ? order by `starts_at` asc',
                    'location' => 'app/Services/Availability/CalendarBuilder.php:118',
                ]), mt_rand(400, 3200), $ts)->max()->count();

                $apm->record('slow_job', $this->pick($this->jobs), mt_rand(2000, 22000), $ts)->max()->count();

                $apm->record('slow_outgoing_request', (string) json_encode(['POST', 'https://api.stripe.com/v1/payment_intents']), mt_rand(700, 5100), $ts)->max()->count();

                $apm->record('exception', (string) json_encode([
                    'class' => 'App\Exceptions\EmailDeliveryFailed',
                    'location' => 'app/Jobs/SendConfirmationNudgesJob.php:64',
                ]), $ts, $ts)->max()->count();

                $apm->record('cache_hit', 'availability', null, $ts)->count();
                $apm->record('cache_miss', 'availability', null, $ts)->count();
                $apm->record('mail', 'contact@yahyacars.ma', null, $ts)->count();
                $apm->record('notification', 'mail', null, $ts)->count();
                $apm->record('queue', 'redis:mail', null, $ts)->count();

                foreach (['default', 'mail', 'maintenance', 'broadcast'] as $queue) {
                    $apm->record('queue_processed', $queue, null, $ts)->count();

                    if (mt_rand(1, 8) === 1) {
                        $apm->record('queue_failed', $queue, null, $ts)->count();
                    }

                    if (mt_rand(1, 10) === 1) {
                        $apm->record('queue_released', $queue, null, $ts)->count();
                    }
                }
                $apm->record('log', 'error', null, $ts)->count();
                $apm->record('user_request', 'anas@remix-it.be', null, $ts)->count();
                $apm->record('user_job', 'anas@remix-it.be', null, $ts)->count();

                // Custom metrics + RUM beacons, recorded exactly as
                // Vigilance::increment()/gauge()/observe() do — a counter read
                // from sum() shows 0 if it is seeded with a null value.
                $apm->record('metric_count', 'bookings.confirmed', mt_rand(1, 4), $ts)->sum()->count();
                $apm->record('metric_value', 'fleet.available_vehicles', mt_rand(40, 120), $ts)->avg()->max()->min();
                $apm->record('metric_distribution', 'checkout.duration_ms', mt_rand(900, 9000), $ts)->count()->avg()->max();

                foreach (['/fr/recherche', '/fr/vehicles/{vehicle}', '/en/teaser'] as $page) {
                    $apm->record('web_vital', (string) json_encode(['lcp', $page]), mt_rand(900, 4200), $ts);
                    $apm->record('web_vital', (string) json_encode(['inp', $page]), mt_rand(40, 620), $ts);
                    $apm->record('web_vital', (string) json_encode(['cls', $page]), mt_rand(1, 40), $ts);
                    $apm->record('web_vital', (string) json_encode(['fcp', $page]), mt_rand(400, 2600), $ts);
                    $apm->record('web_vital', (string) json_encode(['ttfb', $page]), mt_rand(60, 900), $ts);
                }
            }
        }

        $apm->ingest();
    }

    protected function stackTrace(): string
    {
        return <<<'TXT'
        App\Exceptions\EmailDeliveryFailed: Resend email.bounced — to: contact@yahyacars.ma
            at app/Jobs/SendConfirmationNudgesJob.php:64
            at vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:36
            at vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php:129
            at vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php:124
            at vendor/laravel/framework/src/Illuminate/Queue/Worker.php:441
        TXT;
    }
}
