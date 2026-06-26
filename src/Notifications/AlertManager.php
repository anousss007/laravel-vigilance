<?php

namespace Vigilance\Notifications;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Vigilance\Models\Incident;
use Vigilance\Notifications\Contracts\AlertRule;
use Vigilance\Notifications\Rules\AnomalyRule;
use Vigilance\Notifications\Rules\ErrorRateRule;
use Vigilance\Notifications\Rules\ExceptionSpikeRule;
use Vigilance\Notifications\Rules\IssueRegressionRule;
use Vigilance\Notifications\Rules\NewIssueRule;
use Vigilance\Notifications\Rules\QueueLongWaitRule;
use Vigilance\Notifications\Rules\ReleaseHealthRule;
use Vigilance\Notifications\Rules\ScheduledTaskLateRule;
use Vigilance\Notifications\Rules\SloBurnRateRule;
use Vigilance\Notifications\Rules\SlowRequestRateRule;
use Vigilance\Vigilance;

/**
 * Evaluates the configured alert rules each cycle (run from vigilance:snapshot),
 * throttles per alert key, and dispatches surviving alerts to the configured
 * channels: mail, Slack webhook, and any custom sink registered via
 * Vigilance::alertUsing(). Built-in rules cover queue backlog, failure rate,
 * exception spikes, slow-request rate and overdue/failed scheduled tasks.
 */
class AlertManager
{
    /** @var list<class-string<AlertRule>> */
    protected array $builtInRules = [
        QueueLongWaitRule::class,
        ErrorRateRule::class,
        ExceptionSpikeRule::class,
        SlowRequestRateRule::class,
        ScheduledTaskLateRule::class,
        SloBurnRateRule::class,
        NewIssueRule::class,
        IssueRegressionRule::class,
        AnomalyRule::class,
        ReleaseHealthRule::class,
    ];

    public function __construct(protected Container $app) {}

    /**
     * Run every rule and dispatch any (un-throttled) alerts. Returns the number
     * of alerts dispatched.
     */
    public function check(): int
    {
        if (! config('vigilance.alerts.enabled', true)) {
            return 0;
        }

        $throttle = (int) config('vigilance.alerts.throttle_minutes', 15);
        $renotify = (int) config('vigilance.alerts.renotify_minutes', 0);
        $sent = 0;

        foreach ($this->rules() as $rule) {
            try {
                foreach ($rule->evaluate() as $alert) {
                    if ($this->shouldNotify($alert, $throttle, $renotify)) {
                        $this->dispatch($alert);
                        $sent++;
                    }
                }
            } catch (Throwable) {
                // A broken rule must never break the snapshot cycle.
            }
        }

        $this->autoResolveIncidents($throttle);

        return $sent;
    }

    /**
     * Decide whether to actually notify for this alert. When incident tracking
     * is on (the default), we notify ONCE when the incident opens (or escalates
     * in severity) and then stay quiet for the life of the incident — so a
     * sustained condition (e.g. a breaching SLO) doesn't email you every
     * throttle window. Set alerts.renotify_minutes to get periodic reminders
     * while an incident stays open. With incident tracking off, we fall back to
     * the legacy per-key throttle.
     */
    protected function shouldNotify(Alert $alert, int $throttle, int $renotify): bool
    {
        $status = $this->recordIncident($alert);

        if ($status === 'disabled') {
            if (Cache::has($this->cacheKey($alert->key))) {
                return false;
            }

            Cache::put($this->cacheKey($alert->key), true, now()->addMinutes(max(1, $throttle)));

            return true;
        }

        $notify = match ($status) {
            'opened', 'escalated' => true,
            default => $renotify > 0 && ! Cache::has($this->renotifyKey($alert->key)),
        };

        if ($notify && $renotify > 0) {
            Cache::put($this->renotifyKey($alert->key), true, now()->addMinutes($renotify));
        }

        return $notify;
    }

    /**
     * Open an incident for the alert, or refresh an already-open one. Tracks
     * occurrences and last-seen so it can auto-resolve when the alert stops.
     *
     * @return string 'opened' (new), 'escalated' (severity rose), 'ongoing'
     *                (already open, same/lower severity) or 'disabled'
     *                (incident tracking off / unavailable).
     */
    protected function recordIncident(Alert $alert): string
    {
        if (! config('vigilance.alerts.incidents', true)) {
            return 'disabled';
        }

        try {
            $incident = Incident::query()->where('key', $alert->key)->whereNull('resolved_at')->first();

            if ($incident === null) {
                Incident::query()->create([
                    'key' => $alert->key,
                    'title' => $alert->title,
                    'message' => $alert->message,
                    'level' => $alert->level,
                    'status' => 'open',
                    'occurrences' => 1,
                    'opened_at' => now(),
                    'last_seen_at' => now(),
                ]);

                return 'opened';
            }

            // Only escalate (and re-notify) when the severity actually rises;
            // never downgrade a recorded incident's level.
            $escalated = $this->levelRank($alert->level) > $this->levelRank((string) $incident->level);

            // The occurrence count must survive concurrency (multiple nodes can
            // evaluate alerts at once), so bump it with an atomic SQL increment
            // rather than writing back a value read into PHP — otherwise
            // simultaneous occurrences clobber each other and the count drifts
            // low. The other columns are latest-wins and may safely race. Goes
            // through the query builder (not $incident->update()) so the raw
            // expression isn't swallowed by the integer cast on `occurrences`.
            // Mirrors the failure-group counter in FailureGrouper.
            Incident::query()->whereKey($incident->getKey())->update([
                'occurrences' => DB::raw('occurrences + 1'),
                'last_seen_at' => now(),
                'message' => $alert->message,
                'level' => $escalated ? $alert->level : $incident->level,
            ]);

            return $escalated ? 'escalated' : 'ongoing';
        } catch (Throwable) {
            // Incident tracking must never break the snapshot cycle.
            return 'disabled';
        }
    }

    protected function levelRank(string $level): int
    {
        return match ($level) {
            'critical' => 2,
            'warning' => 1,
            default => 0,
        };
    }

    /**
     * Resolve open incidents whose alert hasn't recurred for several throttle
     * windows (the condition has cleared).
     */
    protected function autoResolveIncidents(int $throttle): void
    {
        if (! config('vigilance.alerts.incidents', true)) {
            return;
        }

        try {
            $multiplier = max(2, (int) config('vigilance.alerts.incident_resolve_after', 3));
            $cutoff = now()->subMinutes(max(1, $throttle) * $multiplier);

            Incident::query()
                ->whereNull('resolved_at')
                ->where('last_seen_at', '<', $cutoff)
                ->update(['status' => 'resolved', 'resolved_at' => now()]);
        } catch (Throwable) {
            //
        }
    }

    /**
     * @return list<AlertRule>
     */
    protected function rules(): array
    {
        $classes = array_merge(
            $this->builtInRules,
            array_values((array) config('vigilance.alerts.custom', [])),
        );

        $rules = [];

        foreach ($classes as $class) {
            if (is_string($class) && is_a($class, AlertRule::class, true)) {
                $rules[] = $this->app->make($class);
            }
        }

        return $rules;
    }

    protected function dispatch(Alert $alert): void
    {
        // Custom sink first (Notification objects, SMS, PagerDuty, …).
        if ($sink = Vigilance::alertSink()) {
            try {
                $sink($alert);
            } catch (Throwable) {
                //
            }
        }

        $body = "Vigilance: {$alert->title} — {$alert->message}";

        foreach (Vigilance::mailRecipients() as $email) {
            try {
                Mail::raw($body, fn ($mail) => $mail->to($email)->subject('Vigilance: '.$alert->title));
            } catch (Throwable) {
                //
            }
        }

        if ($webhook = Vigilance::slackWebhook()) {
            try {
                Http::post($webhook, ['text' => $body]);
            } catch (Throwable) {
                //
            }
        }

        if ($discord = Vigilance::discordWebhook()) {
            try {
                Http::post($discord, ['content' => $body]);
            } catch (Throwable) {
                //
            }
        }

        if ($teams = Vigilance::teamsWebhook()) {
            try {
                Http::post($teams, [
                    '@type' => 'MessageCard',
                    '@context' => 'http://schema.org/extensions',
                    'summary' => 'Vigilance alert',
                    'themeColor' => match ($alert->level) {
                        'critical' => 'E11D48',
                        'warning' => 'F59E0B',
                        default => '10B981',
                    },
                    'title' => $alert->title,
                    'text' => $alert->message,
                ]);
            } catch (Throwable) {
                //
            }
        }

        foreach (Vigilance::webhookUrls() as $url) {
            try {
                Http::post($url, [
                    'key' => $alert->key,
                    'title' => $alert->title,
                    'message' => $alert->message,
                    'level' => $alert->level,
                ]);
            } catch (Throwable) {
                //
            }
        }
    }

    protected function cacheKey(string $key): string
    {
        return 'vigilance:alert:'.$key;
    }

    protected function renotifyKey(string $key): string
    {
        return 'vigilance:alert:renotify:'.$key;
    }
}
