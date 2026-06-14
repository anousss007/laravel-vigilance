<?php

namespace Vigilance\Control;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Vigilance\Contracts\Dispatchable;
use Vigilance\Contracts\ShouldNotBeDispatchedManually;

/**
 * Authorization gate for the manual-control surface (dispatching jobs and
 * running artisan commands from the dashboard). It resolves the allowlist of
 * job classes / command names from config and answers per-target checks.
 *
 * "deny" always wins over any allow rule. Discovery is performed via the
 * Symfony Finder over the configured paths and cached per request.
 */
class ControlGate
{
    /** @var array<int, class-string>|null */
    protected static ?array $jobsCache = null;

    /**
     * The dispatchable job classes allowed by the configured mode, with the
     * deny list subtracted.
     *
     * @return array<int, class-string>
     */
    public function jobs(): array
    {
        if (static::$jobsCache !== null) {
            return static::$jobsCache;
        }

        $config = (array) config('vigilance.control.jobs', []);
        $mode = $config['mode'] ?? 'marker';
        $paths = (array) ($config['paths'] ?? []);
        $allow = array_values(array_filter((array) ($config['allow'] ?? [])));
        $deny = array_values(array_filter((array) ($config['deny'] ?? [])));

        $jobs = match ($mode) {
            'list' => $allow,
            'marker' => $this->discover($paths, Dispatchable::class),
            'discover' => $this->discover($paths, ShouldQueue::class),
            'all' => array_merge(
                $allow,
                $this->discover($paths, Dispatchable::class),
                $this->discover($paths, ShouldQueue::class),
            ),
            default => [],
        };

        $jobs = array_values(array_unique(array_filter(
            $jobs,
            fn (string $class) => class_exists($class)
                && ! in_array($class, $deny, true)
                && ! is_a($class, ShouldNotBeDispatchedManually::class, true),
        )));

        return static::$jobsCache = $jobs;
    }

    public function isJobAllowed(string $class): bool
    {
        return in_array($class, $this->jobs(), true);
    }

    /**
     * The artisan command names allowed by the configured mode, with the deny
     * list (and vigilance's own commands) subtracted.
     *
     * @return array<int, string>
     */
    public function commands(): array
    {
        $config = (array) config('vigilance.control.commands', []);
        $mode = $config['mode'] ?? 'list';
        $allow = array_values(array_filter((array) ($config['allow'] ?? [])));
        $deny = array_values(array_filter((array) ($config['deny'] ?? [])));

        $all = $this->registeredCommands();

        $names = match ($mode) {
            'all' => $all,
            // 'list' (and any unknown mode): only names matching an allow
            // pattern. Patterns may use Str::is wildcards (e.g. "app:sync-*").
            default => array_values(array_filter(
                $all,
                fn (string $name) => $this->matchesAny($name, $allow),
            )),
        };

        $names = array_values(array_filter(
            $names,
            fn (string $name) => ! $this->matchesAny($name, $deny) && ! $this->isOwnCommand($name),
        ));

        sort($names);

        return $names;
    }

    public function isCommandAllowed(string $name): bool
    {
        return in_array($name, $this->commands(), true);
    }

    /**
     * Command names that matched the allow rules (or "all") but were ultimately
     * removed by a deny rule or because they are Vigilance's own commands.
     * Surfaced by vigilance:doctor so an operator can see why a command they
     * explicitly allowed never appears on the dashboard.
     *
     * @return array<string, string> [command name => reason]
     */
    public function droppedCommands(): array
    {
        $config = (array) config('vigilance.control.commands', []);
        $mode = $config['mode'] ?? 'list';
        $allow = array_values(array_filter((array) ($config['allow'] ?? [])));
        $deny = array_values(array_filter((array) ($config['deny'] ?? [])));

        $candidates = $mode === 'all'
            ? $this->registeredCommands()
            : array_values(array_filter(
                $this->registeredCommands(),
                fn (string $name) => $this->matchesAny($name, $allow),
            ));

        $dropped = [];

        foreach ($candidates as $name) {
            if ($this->matchesAny($name, $deny)) {
                $dropped[$name] = 'denied';
            } elseif ($this->isOwnCommand($name)) {
                $dropped[$name] = 'vigilance command';
            }
        }

        return $dropped;
    }

    /**
     * Discover concrete classes in the given paths that implement/extend the
     * given marker/contract. Everything is guarded so a malformed file in the
     * host app can never break the dashboard.
     *
     * @param  array<int, mixed>  $paths
     * @param  class-string  $contract
     * @return array<int, class-string>
     */
    protected function discover(array $paths, string $contract): array
    {
        $found = [];

        $paths = array_values(array_filter($paths, fn ($path) => is_string($path) && is_dir($path)));

        if ($paths === []) {
            return [];
        }

        try {
            $files = (new Finder)->files()->name('*.php')->in($paths);
        } catch (\Throwable) {
            return [];
        }

        foreach ($files as $file) {
            try {
                $class = $this->classFromFile($file);

                if ($class === null || ! class_exists($class)) {
                    continue;
                }

                $reflection = new \ReflectionClass($class);

                if ($reflection->isAbstract() || ! $reflection->isInstantiable()) {
                    continue;
                }

                if (is_subclass_of($class, $contract) || in_array($contract, class_implements($class) ?: [], true)) {
                    $found[] = $class;
                }
            } catch (\Throwable) {
                // Skip anything that won't reflect cleanly.
                continue;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Best-effort map a PHP file to its fully-qualified class name by parsing
     * the namespace and class declarations from the file contents.
     */
    protected function classFromFile(SplFileInfo $file): ?string
    {
        try {
            $contents = $file->getContents();
        } catch (\Throwable) {
            return null;
        }

        if (! preg_match('/^\s*class\s+(\w+)/m', $contents, $classMatch)) {
            return null;
        }

        $namespace = '';

        if (preg_match('/^\s*namespace\s+([^;]+);/m', $contents, $nsMatch)) {
            $namespace = trim($nsMatch[1]).'\\';
        }

        return $namespace.$classMatch[1];
    }

    /**
     * All registered artisan command names, guarded so a broken console kernel
     * cannot break the gate.
     *
     * @return array<int, string>
     */
    protected function registeredCommands(): array
    {
        try {
            return array_keys(Artisan::all());
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int, string> $patterns */
    protected function matchesAny(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $name || Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    protected function isOwnCommand(string $name): bool
    {
        return Str::startsWith($name, 'vigilance:');
    }

    /**
     * Reset the per-request discovery cache. Primarily useful in tests where
     * config changes between assertions.
     */
    public static function flush(): void
    {
        static::$jobsCache = null;
    }
}
