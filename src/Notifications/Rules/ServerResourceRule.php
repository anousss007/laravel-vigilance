<?php

namespace Vigilance\Notifications\Rules;

use Vigilance\Apm\Contracts\Storage;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires when a server is running out of CPU, memory or disk.
 *
 * The Servers recorder has always collected these, but nothing consumed them:
 * a disk filling up was visible on the APM page and nowhere else, which is no
 * use at 3am. Reads the latest-wins 'system' snapshot, so it costs one query
 * and no extra collection.
 *
 * Servers whose heartbeat has gone stale are skipped rather than reported on
 * last known values — that is MonitoringHealthRule's job, and alerting on a
 * frozen number would be worse than saying nothing.
 */
class ServerResourceRule implements AlertRule
{
    public function __construct(protected Storage $storage) {}

    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.server_resources.enabled', true)) {
            return;
        }

        $staleAfter = max(30, (int) config('vigilance.alerts.rules.server_resources.stale_after', 300));
        $cutoff = time() - $staleAfter;

        foreach ($this->storage->values('system') as $slug => $row) {
            $data = json_decode((string) ($row->value ?? ''), true);

            if (! is_array($data) || (int) ($data['updated_at'] ?? 0) < $cutoff) {
                continue;
            }

            $name = (string) ($data['name'] ?? $slug);

            yield from $this->cpuAlert($name, $data);
            yield from $this->memoryAlert($name, $data);
            yield from $this->diskAlerts($name, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return iterable<Alert>
     */
    protected function cpuAlert(string $name, array $data): iterable
    {
        $threshold = (int) config('vigilance.alerts.rules.server_resources.cpu', 90);
        $cpu = (int) ($data['cpu'] ?? 0);

        if ($threshold <= 0 || $cpu < $threshold) {
            return;
        }

        yield new Alert(
            key: 'server_cpu:'.$name,
            title: 'Server CPU saturated',
            message: "[{$name}] is at {$cpu}% CPU, above the {$threshold}% threshold.",
            level: $this->level($cpu, $threshold),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return iterable<Alert>
     */
    protected function memoryAlert(string $name, array $data): iterable
    {
        $threshold = (int) config('vigilance.alerts.rules.server_resources.memory', 90);
        $total = (int) ($data['memory_total'] ?? 0);
        $used = (int) ($data['memory_used'] ?? 0);

        // A host where detection is unsupported reports 0/0 rather than
        // throwing; there is nothing to judge, so stay quiet.
        if ($threshold <= 0 || $total <= 0) {
            return;
        }

        $percent = (int) round($used / $total * 100);

        if ($percent < $threshold) {
            return;
        }

        yield new Alert(
            key: 'server_memory:'.$name,
            title: 'Server memory nearly exhausted',
            message: "[{$name}] is using {$percent}% of memory ({$used} MB of {$total} MB), "
                ."above the {$threshold}% threshold.",
            level: $this->level($percent, $threshold),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return iterable<Alert>
     */
    protected function diskAlerts(string $name, array $data): iterable
    {
        $threshold = (int) config('vigilance.alerts.rules.server_resources.disk', 90);

        if ($threshold <= 0) {
            return;
        }

        foreach ((array) ($data['storage'] ?? []) as $disk) {
            if (! is_array($disk)) {
                continue;
            }

            $total = (int) ($disk['total'] ?? 0);
            $used = (int) ($disk['used'] ?? 0);
            $directory = (string) ($disk['directory'] ?? '');

            if ($total <= 0) {
                continue;
            }

            $percent = (int) round($used / $total * 100);

            if ($percent < $threshold) {
                continue;
            }

            $freeGb = round(($total - $used) / 1024, 1);

            yield new Alert(
                key: 'server_disk:'.$name.':'.$directory,
                title: 'Server disk nearly full',
                message: "[{$name}] disk {$directory} is {$percent}% full ({$freeGb} GB free), "
                    ."above the {$threshold}% threshold.",
                level: $this->level($percent, $threshold),
            );
        }
    }

    /**
     * Escalate to critical once usage is most of the way from the threshold to
     * full — 91% of a disk is a warning, 98% is about to take the app down.
     */
    protected function level(int $percent, int $threshold): string
    {
        $critical = (int) config('vigilance.alerts.rules.server_resources.critical', 97);

        return $percent >= $critical ? 'critical' : 'warning';
    }
}
