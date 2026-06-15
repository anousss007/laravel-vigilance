<?php

namespace Vigilance\Support;

/**
 * Builds cross-driver LIKE patterns. The catch: class names contain
 * backslashes, and Postgres/MySQL treat "\" as the default LIKE escape
 * character while SQLite does not — so a pattern like "App\Jobs\Noisy%" matches
 * on SQLite but silently fails on Postgres/MySQL. We sidestep that by escaping
 * the LIKE metacharacters with an explicit "!" escape and always pairing the
 * query with `ESCAPE '!'`, which behaves identically on every driver.
 *
 * Usage: `->whereRaw('name like ? escape ?', [Like::fromWildcard($p), Like::ESCAPE])`.
 */
final class Like
{
    public const ESCAPE = '!';

    /**
     * Turn a user wildcard pattern ("App\Jobs\Noisy*") into a LIKE pattern:
     * metacharacters are escaped and "*" becomes "%".
     */
    public static function fromWildcard(string $pattern): string
    {
        return str_replace('*', '%', self::escape($pattern));
    }

    /**
     * Escape LIKE metacharacters so the value is matched literally (e.g. a
     * "%term%" contains-search where the term may itself contain % or _ or \).
     */
    public static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * A literal "contains" pattern (%term%) with the term escaped.
     */
    public static function contains(string $value): string
    {
        return '%'.self::escape($value).'%';
    }
}
