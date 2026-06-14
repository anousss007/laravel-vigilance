<?php

namespace Vigilance\Support;

/**
 * Sane default lists referenced from the published config file. Kept in code
 * (rather than inline in config) so they can evolve without forcing users to
 * re-publish their config.
 */
class Defaults
{
    /**
     * Framework / vendor commands that are noise in a monitoring context.
     *
     * @return list<string>
     */
    public static function frameworkCommands(): array
    {
        return [
            'list',
            'help',
            'tinker',
            'inspire',
            'serve',
            'about',
            'completion',
            'optimize',
            'optimize:clear',
            'config:cache',
            'config:clear',
            'route:cache',
            'route:clear',
            'view:cache',
            'view:clear',
            'event:cache',
            'event:clear',
            'vendor:publish',
        ];
    }

    /**
     * Commands that must never be runnable from the dashboard, regardless of
     * the allow rules. These are destructive or interactive.
     *
     * @return list<string>
     */
    public static function dangerousCommands(): array
    {
        return [
            'migrate:fresh',
            'migrate:reset',
            'migrate:rollback',
            'db:wipe',
            'tinker',
            'env:encrypt',
            'env:decrypt',
            'key:generate',
            'queue:flush',
            'queue:forget',
        ];
    }
}
