<?php

namespace Vigilance;

use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Vigilance\Contracts\ShouldNotBeMonitored;
use Vigilance\Events\ExceptionReported;
use Vigilance\Notifications\Alert;

/**
 * Central coordination object: authorization gate, recording state, ignore
 * rules and the "manual run" context used to attribute dashboard-triggered
 * jobs/commands to the user who launched them.
 */
class Vigilance
{
    public static string $version = '0.3.0';

    /** Cache-busting token for the bundled stylesheet, derived from its contents. */
    protected static ?string $assetVersion = null;

    /** @var (Closure(mixed): bool)|null */
    protected static ?Closure $authUsing = null;

    /** @var (Closure(mixed): ?string)|null */
    protected static ?Closure $userResolver = null;

    protected static bool $recording = true;

    /** @var array{user: ?string, via: string}|null */
    protected static ?array $manualContext = null;

    /** @var list<string>|null null = not set in code, fall back to config */
    protected static ?array $mailRecipients = null;

    protected static ?string $slackWebhook = null;

    protected static ?string $discordWebhook = null;

    protected static ?string $teamsWebhook = null;

    /** @var list<string>|null */
    protected static ?array $webhookUrls = null;

    /** @var (Closure(Alert): void)|null */
    protected static ?Closure $alertSink = null;

    /** @var (Closure(list<string>): array<string, array<string, mixed>>)|null */
    protected static ?Closure $apmUserResolver = null;

    /**
     * Register the callback used to authorize dashboard access. Defaults to
     * "local environment only" (secure by default).
     *
     * @param  Closure(mixed): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Whether a custom authorization callback has been registered (used by
     * vigilance:doctor to warn about the local-only default).
     */
    public static function hasCustomAuth(): bool
    {
        return static::$authUsing !== null;
    }

    /**
     * A cache-busting token for the bundled stylesheet, derived from the file's
     * contents (not the version) so the URL changes whenever the CSS changes —
     * across dev builds and releases alike. This makes the long-lived,
     * "immutable" cache header safe: the browser only re-fetches when the bytes
     * actually change. Cached per process.
     */
    public static function assetVersion(): string
    {
        if (static::$assetVersion !== null) {
            return static::$assetVersion;
        }

        $path = __DIR__.'/../resources/dist/vigilance.css';
        $hash = is_file($path) ? substr((string) @md5_file($path), 0, 12) : '';

        return static::$assetVersion = ($hash !== '' ? $hash : static::$version);
    }

    public static function check(mixed $request = null): bool
    {
        // An explicit Vigilance::auth() callback always wins.
        if (static::$authUsing !== null) {
            return (bool) (static::$authUsing)($request);
        }

        $request ??= request();
        $user = (is_object($request) && method_exists($request, 'user')) ? $request->user() : null;
        $gate = Gate::forUser($user);

        // If the application defined a "viewVigilance" ability, it (together with
        // any Gate::before hook) is the sole authority — exactly like Horizon's
        // viewHorizon / Telescope / Pulse.
        if ($gate->has('viewVigilance')) {
            return (bool) $gate->check('viewVigilance', [$request]);
        }

        // No explicit ability: still run the Gate so a Gate::before rule (e.g.
        // "admins can do anything") can grant access; otherwise fall back to the
        // secure local-only default.
        return (bool) $gate->check('viewVigilance', [$request]) || app()->environment('local');
    }

    /**
     * Customize how the acting user is identified for audit/attribution.
     *
     * @param  Closure(mixed): ?string  $callback
     */
    public static function resolveUserUsing(Closure $callback): void
    {
        static::$userResolver = $callback;
    }

    /**
     * Identify the current user for audit/attribution. Defaults to the
     * authenticated user's email, falling back to their auth identifier.
     */
    public static function currentUser(mixed $request = null): ?string
    {
        $request ??= request();

        if (static::$userResolver !== null) {
            return (static::$userResolver)($request);
        }

        $user = method_exists($request, 'user') ? $request->user() : null;

        if ($user === null) {
            return null;
        }

        $identifier = $user->email ?? (method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null);

        return $identifier !== null ? (string) $identifier : null;
    }

    /**
     * Reset all per-request static state. Wired to Octane request boundaries
     * so long-lived workers never leak recording/attribution state.
     */
    public static function flushState(): void
    {
        static::$recording = true;
        static::$manualContext = null;
    }

    public static function shouldRecord(): bool
    {
        return static::$recording && (bool) config('vigilance.enabled', true);
    }

    public static function recordOn(): void
    {
        static::$recording = true;
    }

    public static function recordOff(): void
    {
        static::$recording = false;
    }

    /**
     * Surface a caught/swallowed exception to the APM exception card (and the
     * active trace) without re-throwing it.
     */
    public static function report(\Throwable $e): void
    {
        try {
            app('events')->dispatch(new ExceptionReported($e));
        } catch (\Throwable) {
            // Monitoring must never break the caller.
        }
    }

    /**
     * Run a callback without recording (prevents the recorder from observing
     * its own writes).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutRecording(Closure $callback): mixed
    {
        $previous = static::$recording;
        static::$recording = false;

        try {
            return $callback();
        } finally {
            static::$recording = $previous;
        }
    }

    /**
     * Mark everything dispatched/run inside the callback as a manual action
     * attributed to $user, so captured runs are flagged accordingly.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asManual(?string $user, Closure $callback): mixed
    {
        $previous = static::$manualContext;
        static::$manualContext = ['user' => $user, 'via' => 'manual'];

        try {
            return $callback();
        } finally {
            static::$manualContext = $previous;
        }
    }

    /** @return array{user: ?string, via: string}|null */
    public static function manualContext(): ?array
    {
        return static::$manualContext;
    }

    public static function ignoresCommand(?string $name): bool
    {
        if ($name === null || $name === '') {
            return true;
        }

        foreach ((array) config('vigilance.except.commands', []) as $pattern) {
            if (Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    public static function ignoresJob(object|string $job): bool
    {
        $class = is_object($job) ? get_class($job) : $job;

        if (is_a($class, ShouldNotBeMonitored::class, true)) {
            return true;
        }

        foreach ((array) config('vigilance.except.jobs', []) as $pattern) {
            if (Str::is($pattern, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a successful run survives sampling. Failures bypass this.
     */
    public static function passesSampling(): bool
    {
        $rate = (float) config('vigilance.capture.sample_rate', 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $rate;
    }

    /** @return list<string> Job class patterns to silence from the main feed. */
    public static function silencedJobPatterns(): array
    {
        return array_values((array) config('vigilance.silence.jobs', []));
    }

    /** @return list<string> Tags that silence a run from the main feed. */
    public static function silencedTags(): array
    {
        return array_values((array) config('vigilance.silence.tags', []));
    }

    /**
     * Whether a run should be silenced (kept out of the main Runs feed) given
     * its name and tags.
     *
     * @param  array<int, string>  $tags
     */
    public static function isSilenced(?string $name, array $tags = []): bool
    {
        if ($name !== null) {
            foreach (static::silencedJobPatterns() as $pattern) {
                if (Str::is($pattern, $name)) {
                    return true;
                }
            }
        }

        return array_intersect($tags, static::silencedTags()) !== [];
    }

    /**
     * Route long-wait / health / alert notifications to one or more email
     * addresses. Calling this explicitly overrides the
     * `vigilance.notifications.mail` config value (which reads from
     * `VIGILANCE_ALERT_EMAILS` and is used automatically when this is not set).
     *
     * @param  string|list<string>  $emails
     */
    public static function routeMailNotificationsTo(string|array $emails): void
    {
        static::$mailRecipients = static::parseList($emails);
    }

    /**
     * Route alert notifications to a Slack incoming-webhook URL. Overrides the
     * `vigilance.notifications.slack` config value (from `VIGILANCE_SLACK_WEBHOOK`).
     */
    public static function routeSlackNotificationsTo(string $webhookUrl): void
    {
        static::$slackWebhook = $webhookUrl;
    }

    /**
     * Resolved mail recipients — an explicit routeMailNotificationsTo() call if
     * one was made, otherwise the comma-separated config/env value.
     *
     * @return list<string>
     */
    public static function mailRecipients(): array
    {
        if (static::$mailRecipients !== null) {
            return static::$mailRecipients;
        }

        return static::parseList(config('vigilance.notifications.mail'));
    }

    public static function slackWebhook(): ?string
    {
        return static::$slackWebhook ?? (((string) config('vigilance.notifications.slack')) ?: null);
    }

    /** Route alerts to a Discord incoming-webhook URL. */
    public static function routeDiscordNotificationsTo(string $webhookUrl): void
    {
        static::$discordWebhook = $webhookUrl;
    }

    /** Route alerts to a Microsoft Teams incoming-webhook URL. */
    public static function routeTeamsNotificationsTo(string $webhookUrl): void
    {
        static::$teamsWebhook = $webhookUrl;
    }

    /**
     * Route alerts to one or more generic webhook URLs (each receives the alert
     * as JSON — point them at PagerDuty, Opsgenie, a custom handler, …).
     *
     * @param  string|list<string>  $urls
     */
    public static function routeWebhooksTo(string|array $urls): void
    {
        static::$webhookUrls = static::parseList($urls);
    }

    public static function discordWebhook(): ?string
    {
        return static::$discordWebhook ?? (((string) config('vigilance.notifications.discord')) ?: null);
    }

    public static function teamsWebhook(): ?string
    {
        return static::$teamsWebhook ?? (((string) config('vigilance.notifications.teams')) ?: null);
    }

    /** @return list<string> */
    public static function webhookUrls(): array
    {
        if (static::$webhookUrls !== null) {
            return static::$webhookUrls;
        }

        return static::parseList(config('vigilance.notifications.webhooks'));
    }

    public static function hasNotificationRouting(): bool
    {
        return static::mailRecipients() !== []
            || static::slackWebhook() !== null
            || static::discordWebhook() !== null
            || static::teamsWebhook() !== null
            || static::webhookUrls() !== [];
    }

    /**
     * Normalise a single value, a comma-separated string, or an array into a
     * clean, trimmed list (used for e-mail recipients and webhook URLs).
     *
     * @param  string|array<int, string>|null  $value
     * @return list<string>
     */
    protected static function parseList(string|array|null $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', $value);

        return array_values(array_filter(array_map('trim', $items)));
    }

    /**
     * Register a custom sink for alerts (e.g. dispatch a Laravel Notification,
     * an SMS, a PagerDuty incident). Runs alongside the mail / Slack channels.
     *
     * @param  Closure(Alert): void  $sink
     */
    public static function alertUsing(Closure $sink): void
    {
        static::$alertSink = $sink;
    }

    /** @return (Closure(Alert): void)|null */
    public static function alertSink(): ?Closure
    {
        return static::$alertSink;
    }

    /**
     * Customise how APM user ids are resolved to display info at dashboard-render
     * time (name / extra / avatar). Defaults to the auth provider's user model
     * plus a Gravatar.
     *
     * @param  Closure(list<string>): array<string, array<string, mixed>>  $resolver
     */
    public static function resolveApmUsersUsing(Closure $resolver): void
    {
        static::$apmUserResolver = $resolver;
    }

    /**
     * Resolve display info for a set of APM user ids.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    public static function apmUsers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        if (static::$apmUserResolver !== null) {
            return (static::$apmUserResolver)($ids);
        }

        return static::defaultApmUsers($ids);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    protected static function defaultApmUsers(array $ids): array
    {
        try {
            $model = config('auth.providers.users.model');

            if (! is_string($model) || ! class_exists($model)) {
                return [];
            }

            $out = [];

            foreach ($model::query()->findMany($ids) as $user) {
                $id = (string) $user->getAuthIdentifier();
                $email = data_get($user, 'email');
                $email = is_string($email) ? $email : null;

                $out[$id] = [
                    'name' => (string) (data_get($user, 'name') ?? $email ?? ('User #'.$id)),
                    'extra' => $email ?? '',
                    'avatar' => $email !== null
                        ? 'https://www.gravatar.com/avatar/'.md5(strtolower(trim($email))).'?d=mp&s=40'
                        : null,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
