<?php

namespace Vigilance\Notifications;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Vigilance\Notifications\Contracts\AlertRule;
use Vigilance\Notifications\Rules\ErrorRateRule;
use Vigilance\Notifications\Rules\ExceptionSpikeRule;
use Vigilance\Notifications\Rules\QueueLongWaitRule;
use Vigilance\Notifications\Rules\ScheduledTaskLateRule;
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
        $sent = 0;

        foreach ($this->rules() as $rule) {
            try {
                foreach ($rule->evaluate() as $alert) {
                    if (Cache::has($this->cacheKey($alert->key))) {
                        continue;
                    }

                    Cache::put($this->cacheKey($alert->key), true, now()->addMinutes(max(1, $throttle)));
                    $this->dispatch($alert);
                    $sent++;
                }
            } catch (Throwable) {
                // A broken rule must never break the snapshot cycle.
            }
        }

        return $sent;
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
    }

    protected function cacheKey(string $key): string
    {
        return 'vigilance:alert:'.$key;
    }
}
