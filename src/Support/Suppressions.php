<?php

namespace Vigilance\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;
use Vigilance\Models\Suppression;

/**
 * Reads the dashboard-created telemetry rules on the hot path.
 *
 * These are consulted for every request, query and cache key, so they can never
 * be a database round trip. The whole (small, bounded) set is cached and kept
 * in a process-local memo; writes bust the cache, and everything is rescued so
 * a broken cache or a missing table degrades to "no extra rules" rather than
 * taking down the application being observed.
 */
class Suppressions
{
    protected const CACHE_KEY = 'vigilance:suppressions';

    /** @var array<string, list<array{pattern: string, action: string, replacement: ?string}>>|null */
    protected static ?array $memo = null;

    public static function flushState(): void
    {
        static::$memo = null;
    }

    public static function forget(): void
    {
        try {
            Cache::forget(static::CACHE_KEY);
        } catch (Throwable) {
            //
        }

        static::$memo = null;
    }

    /**
     * Whether this value is suppressed for the given scope.
     */
    public static function ignores(string $scope, string $value): bool
    {
        foreach (static::for($scope) as $rule) {
            if ($rule['action'] === 'ignore' && PathMatcher::matchesAny($value, [$rule['pattern']])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collapse a high-cardinality value into its group, or return it unchanged.
     */
    public static function group(string $scope, string $value): string
    {
        foreach (static::for($scope) as $rule) {
            if ($rule['action'] !== 'group' || $rule['replacement'] === null) {
                continue;
            }

            if (PathMatcher::matchesAny($value, [$rule['pattern']])) {
                return $rule['replacement'];
            }
        }

        return $value;
    }

    /**
     * @return list<array{pattern: string, action: string, replacement: ?string}>
     */
    public static function for(string $scope): array
    {
        return static::all()[$scope] ?? [];
    }

    /**
     * @return array<string, list<array{pattern: string, action: string, replacement: ?string}>>
     */
    public static function all(): array
    {
        if (static::$memo !== null) {
            return static::$memo;
        }

        try {
            $rules = Cache::remember(static::CACHE_KEY, now()->addMinutes(5), function () {
                $grouped = [];

                foreach (Suppression::query()->active()->get() as $suppression) {
                    $grouped[$suppression->scope][] = [
                        'pattern' => (string) $suppression->pattern,
                        'action' => (string) $suppression->action,
                        'replacement' => $suppression->replacement,
                    ];
                }

                return $grouped;
            });
        } catch (Throwable) {
            // No table yet (pre-migration), no cache, no database — none of
            // which may break the request being observed.
            $rules = [];
        }

        return static::$memo = $rules;
    }
}
