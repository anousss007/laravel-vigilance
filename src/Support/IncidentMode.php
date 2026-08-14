<?php

namespace Vigilance\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Turn everything up, briefly.
 *
 * Mid-incident you want the detail you deliberately sample away in normal
 * operation: every trace kept, nothing sampled out, debug-level logs. The
 * reason that is normally a config change nobody makes is that a config change
 * nobody makes is also a config change nobody *reverts* — full tracing gets
 * left on for three weeks and the bill or the disk pays for it.
 *
 * So this is a switch with a timer. It is stored in the cache with a TTL, which
 * means expiry is not something that has to run: the moment the entry is gone,
 * the override is gone, even if the app crashed, was redeployed, or nothing
 * ever ran a scheduler again.
 *
 * Every read is rescued — a cache store that is down must degrade to "not in
 * incident mode" rather than take out the request it is supposed to observe.
 */
class IncidentMode
{
    public const CACHE_KEY = 'vigilance:incident-mode';

    /**
     * Per-request memo. Without it every sampling decision — and there are many
     * per request — would be its own cache round trip, which for a Redis store
     * dwarfs the ~9µs the rest of the capture path is budgeted at. Cleared at
     * Octane request boundaries via Vigilance::flushState().
     *
     * false = looked up, nothing engaged. null = not looked up yet.
     *
     * @var array{until: int, by: ?string}|false|null
     */
    protected static array|false|null $memo = null;

    public static function flushState(): void
    {
        static::$memo = null;
    }

    /**
     * Engage incident mode for the given number of minutes.
     *
     * @return array{until: int, minutes: int}
     */
    public static function engage(int $minutes, ?string $user = null): array
    {
        $minutes = max(1, min($minutes, static::maxMinutes()));
        $until = time() + ($minutes * 60);

        Cache::put(static::CACHE_KEY, [
            'until' => $until,
            'by' => $user,
        ], now()->addMinutes($minutes));

        static::$memo = null;

        return ['until' => $until, 'minutes' => $minutes];
    }

    public static function disengage(): void
    {
        try {
            Cache::forget(static::CACHE_KEY);
        } catch (Throwable) {
            //
        }

        static::$memo = null;
    }

    public static function active(): bool
    {
        return static::state() !== null;
    }

    /**
     * @return array{until: int, by: ?string}|null
     */
    public static function state(): ?array
    {
        // Off by default: when the capability is not enabled, this costs one
        // config lookup and never touches the cache at all.
        if (! config('vigilance.incident_mode.enabled', false)) {
            return null;
        }

        if (static::$memo !== null) {
            return static::$memo === false ? null : static::$memo;
        }

        try {
            $state = Cache::get(static::CACHE_KEY);
        } catch (Throwable) {
            // The cache being unavailable must never break the host app; the
            // safe answer is "no override".
            static::$memo = false;

            return null;
        }

        if (! is_array($state) || ! isset($state['until'])) {
            static::$memo = false;

            return null;
        }

        // Belt and braces: the TTL should already have evicted this, but a
        // store with coarse expiry (or a clock skew) must not leave the
        // override on forever.
        if ((int) $state['until'] <= time()) {
            static::disengage();
            static::$memo = false;

            return null;
        }

        return static::$memo = ['until' => (int) $state['until'], 'by' => $state['by'] ?? null];
    }

    public static function secondsRemaining(): int
    {
        $state = static::state();

        return $state === null ? 0 : max(0, $state['until'] - time());
    }

    /**
     * The sample rate to use while engaged — 1.0 unless configured otherwise.
     */
    public static function sampleRate(): float
    {
        return (float) config('vigilance.incident_mode.sample_rate', 1.0);
    }

    public static function tracingEnabled(): bool
    {
        return (bool) config('vigilance.incident_mode.tracing', true);
    }

    public static function logLevel(): ?string
    {
        $level = config('vigilance.incident_mode.log_level', 'debug');

        return is_string($level) && $level !== '' ? $level : null;
    }

    /**
     * A hard ceiling on how long the override can be engaged for, so "briefly"
     * stays true even if someone types 60000 into the box.
     */
    public static function maxMinutes(): int
    {
        return max(1, (int) config('vigilance.incident_mode.max_minutes', 120));
    }
}
