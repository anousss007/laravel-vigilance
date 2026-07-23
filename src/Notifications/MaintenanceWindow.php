<?php

namespace Vigilance\Notifications;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Alert suppression during planned maintenance. While a window is active,
 * AlertManager stops notifying — rules aren't dispatched, so a deploy or a known
 * noisy migration doesn't page anyone. When the window ends, the next snapshot
 * cycle re-evaluates and notifies for anything still breaching, so nothing is
 * permanently lost.
 *
 * Two sources, either of which suppresses:
 *  - an ad-hoc window an operator/agent opens (cache flag with an expiry), and
 *  - recurring windows declared in config (e.g. nightly batch hours).
 */
class MaintenanceWindow
{
    protected const KEY = 'vigilance:maintenance:until';

    protected function cache(): Repository
    {
        return Cache::store(config('vigilance.notifications.cache_store'));
    }

    /**
     * Open an ad-hoc maintenance window for the given number of minutes.
     */
    public function start(int $minutes): int
    {
        $minutes = max(1, $minutes);
        $until = Carbon::now()->getTimestamp() + $minutes * 60;

        // TTL a touch beyond the window so the flag self-clears if never stopped.
        $this->cache()->put(self::KEY, $until, $minutes * 60 + 60);

        return $until;
    }

    public function stop(): void
    {
        $this->cache()->forget(self::KEY);
    }

    /**
     * The ad-hoc window's expiry epoch, or null when none is open.
     */
    public function adHocUntil(): ?int
    {
        $until = $this->cache()->get(self::KEY);

        if ($until === null) {
            return null;
        }

        if ((int) $until <= Carbon::now()->getTimestamp()) {
            $this->cache()->forget(self::KEY);

            return null;
        }

        return (int) $until;
    }

    /**
     * Whether alert notifications are currently suppressed.
     */
    public function active(): bool
    {
        return $this->adHocUntil() !== null || $this->inRecurringWindow(Carbon::now());
    }

    /**
     * Match "now" against the recurring windows in config:
     * notifications.maintenance = [ ['days' => ['sat','sun'], 'from' => '02:00', 'to' => '04:00'], … ]
     * "days" is optional (defaults to every day); a window whose "to" is <= "from"
     * wraps past midnight.
     */
    protected function inRecurringWindow(Carbon $now): bool
    {
        $windows = config('vigilance.notifications.maintenance', []);

        if (! is_array($windows)) {
            return false;
        }

        $day = strtolower($now->format('D')); // mon, tue, …
        $minutes = $now->hour * 60 + $now->minute;

        foreach ($windows as $window) {
            if (! is_array($window)) {
                continue;
            }

            $days = array_map('strtolower', (array) ($window['days'] ?? []));
            if ($days !== [] && ! in_array($day, $days, true) && ! in_array(substr($day, 0, 3), $days, true)) {
                continue;
            }

            $from = $this->toMinutes($window['from'] ?? null);
            $to = $this->toMinutes($window['to'] ?? null);

            if ($from === null || $to === null) {
                continue;
            }

            $match = $to > $from
                ? ($minutes >= $from && $minutes < $to)
                : ($minutes >= $from || $minutes < $to); // wraps midnight

            if ($match) {
                return true;
            }
        }

        return false;
    }

    protected function toMinutes(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
