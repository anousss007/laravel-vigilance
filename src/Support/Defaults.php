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
     * Long-running daemon commands that never "finish" under normal operation.
     *
     * These are excluded from command capture unconditionally (regardless of
     * the published config), because a command run is modelled as start→finish:
     * a daemon would sit in "running" forever and, when the process is killed
     * by a signal (deploy, restart, OOM), be left dangling as a stuck "running"
     * row with no end. The user-facing "except.commands" list mirrors these for
     * discoverability, but this baseline guarantees protection even for configs
     * published before a given daemon was added here.
     *
     * @return list<string>
     */
    public static function daemonCommands(): array
    {
        return [
            'queue:work',
            'queue:listen',
            'schedule:work',
            'horizon',
            'horizon:*',
            'octane:start',
            'reverb:start',
            'pulse:work',
            'pulse:check',
            'vigilance:supervise',
            'vigilance:work',
        ];
    }

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
