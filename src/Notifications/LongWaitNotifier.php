<?php

namespace Vigilance\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Vigilance\Metrics\Workload;
use Vigilance\Vigilance;

/**
 * Alerts the configured recipients (mail / Slack webhook) when a queue's
 * estimated time-to-clear exceeds the threshold. Throttled per queue so a
 * sustained backlog doesn't spam. Run from vigilance:snapshot.
 */
class LongWaitNotifier
{
    public function __construct(protected Workload $workload) {}

    public function check(): int
    {
        if (! config('vigilance.notifications.enabled', true) || ! Vigilance::hasNotificationRouting()) {
            return 0;
        }

        $threshold = (int) config('vigilance.notifications.long_wait_seconds', 60);
        $throttle = (int) config('vigilance.notifications.throttle_minutes', 15);
        $sent = 0;

        foreach ($this->workload->queues() as $queue) {
            $clearMs = $queue['time_to_clear_ms'] ?? null;

            if ($clearMs === null) {
                continue;
            }

            $seconds = (int) round($clearMs / 1000);

            if ($seconds < $threshold) {
                continue;
            }

            $key = 'vigilance:longwait:'.$queue['connection_name'].':'.$queue['queue'];

            if (Cache::has($key)) {
                continue;
            }

            Cache::put($key, true, now()->addMinutes(max(1, $throttle)));
            $this->notify($queue, $seconds);
            $sent++;
        }

        return $sent;
    }

    /**
     * @param  array{connection_name: ?string, queue: string, depth: ?int, workers: int}  $queue
     */
    protected function notify(array $queue, int $seconds): void
    {
        $name = ($queue['connection_name'] ?? 'default').':'.$queue['queue'];
        $message = "Vigilance: queue [{$name}] is backing up — estimated time to clear {$seconds}s "
            ."(depth {$queue['depth']}, {$queue['workers']} worker(s)).";

        foreach (Vigilance::mailRecipients() as $email) {
            try {
                Mail::raw($message, fn ($mail) => $mail->to($email)->subject('Vigilance: queue backing up'));
            } catch (\Throwable) {
                // mail not configured / transient failure — never break the snapshot
            }
        }

        if ($webhook = Vigilance::slackWebhook()) {
            try {
                Http::post($webhook, ['text' => $message]);
            } catch (\Throwable) {
                // ignore webhook failures
            }
        }
    }
}
