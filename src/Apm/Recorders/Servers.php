<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;
use Vigilance\Apm\Events\SharedBeat;

/**
 * Records this server's CPU / memory / storage on each beat (throttled). CPU and
 * memory go to ->avg()->onlyBuckets() rolled metrics; the full snapshot is a
 * latest-wins 'system' value. Detection is best-effort cross-platform (Linux,
 * macOS, Windows) and degrades to 0 rather than throwing where unavailable, so
 * the heartbeat never crashes on an unsupported host.
 */
class Servers extends Recorder
{
    /** @var list<class-string> */
    public array $listen = [SharedBeat::class];

    public function record(SharedBeat $event): void
    {
        $interval = (int) $this->recorderConfig('interval', 15);
        $slug = $this->slug();

        // Throttle: only sample this server once per interval (the detection
        // shell-outs are the only expensive part of the whole recorder).
        if (Cache::add('vigilance:apm:server:'.$slug, true, $interval) === false) {
            return;
        }

        $memory = $this->memory();
        $cpu = $this->cpu();
        $storage = $this->storage();
        $timestamp = $event->time->getTimestamp();

        $this->apm->record('cpu', $slug, $cpu, $timestamp)->avg()->onlyBuckets();
        $this->apm->record('memory', $slug, $memory['used'], $timestamp)->avg()->onlyBuckets();

        $this->apm->set('system', $slug, (string) json_encode([
            'name' => $this->name(),
            'cpu' => $cpu,
            'memory_used' => $memory['used'],
            'memory_total' => $memory['total'],
            'storage' => $storage,
            'updated_at' => $timestamp,
        ]), $timestamp);
    }

    protected function name(): string
    {
        return (string) ($this->recorderConfig('name') ?: gethostname() ?: 'server');
    }

    protected function slug(): string
    {
        return Str::slug($this->name()) ?: 'server';
    }

    /** @return array{used:int, total:int} */
    protected function memory(): array
    {
        try {
            return match (PHP_OS_FAMILY) {
                'Linux' => $this->linuxMemory(),
                'Darwin' => $this->darwinMemory(),
                'Windows' => $this->windowsMemory(),
                default => ['total' => 0, 'used' => 0],
            };
        } catch (Throwable) {
            return ['total' => 0, 'used' => 0];
        }
    }

    /** @return array{used:int, total:int} */
    protected function linuxMemory(): array
    {
        if (! is_readable('/proc/meminfo') || ($info = @file_get_contents('/proc/meminfo')) === false) {
            return ['total' => 0, 'used' => 0];
        }

        preg_match('/MemTotal:\s+(\d+) kB/', $info, $total);
        preg_match('/MemAvailable:\s+(\d+) kB/', $info, $available);

        $totalMb = isset($total[1]) ? (int) round(((int) $total[1]) / 1024) : 0;
        $availMb = isset($available[1]) ? (int) round(((int) $available[1]) / 1024) : 0;

        return ['total' => $totalMb, 'used' => max(0, $totalMb - $availMb)];
    }

    /** @return array{used:int, total:int} */
    protected function darwinMemory(): array
    {
        $totalBytes = (int) $this->run('sysctl -n hw.memsize 2>/dev/null');
        $pageSize = (int) $this->run('pagesize 2>/dev/null') ?: 4096;
        $freePages = (int) $this->run("vm_stat 2>/dev/null | awk '/Pages free/ {gsub(/\\./,\"\"); print \$3}'");

        $totalMb = (int) round($totalBytes / 1_048_576);
        $freeMb = (int) round(($freePages * $pageSize) / 1_048_576);

        return ['total' => $totalMb, 'used' => max(0, $totalMb - $freeMb)];
    }

    /** @return array{used:int, total:int} */
    protected function windowsMemory(): array
    {
        $json = $this->powershell(
            'Get-CimInstance Win32_OperatingSystem | Select-Object TotalVisibleMemorySize,FreePhysicalMemory | ConvertTo-Json -Compress'
        );

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return ['total' => 0, 'used' => 0];
        }

        // Both values are reported in kilobytes.
        $totalMb = (int) round(((int) ($data['TotalVisibleMemorySize'] ?? 0)) / 1024);
        $freeMb = (int) round(((int) ($data['FreePhysicalMemory'] ?? 0)) / 1024);

        return ['total' => $totalMb, 'used' => max(0, $totalMb - $freeMb)];
    }

    protected function cpu(): int
    {
        try {
            return match (PHP_OS_FAMILY) {
                'Linux', 'Darwin', 'BSD' => $this->loadAverageCpu(),
                'Windows' => $this->windowsCpu(),
                default => 0,
            };
        } catch (Throwable) {
            return 0;
        }
    }

    protected function loadAverageCpu(): int
    {
        if (! function_exists('sys_getloadavg')) {
            return 0;
        }

        $load = @sys_getloadavg();

        if (! is_array($load)) {
            return 0;
        }

        return (int) min(100, round(((float) $load[0]) / max(1, $this->cores()) * 100));
    }

    protected function windowsCpu(): int
    {
        $value = $this->powershell(
            '(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average'
        );

        return (int) min(100, max(0, (int) trim($value)));
    }

    protected function cores(): int
    {
        $configured = (int) $this->recorderConfig('cores', 0);

        if ($configured > 0) {
            return $configured;
        }

        $cores = (int) $this->run('nproc 2>/dev/null');

        return $cores > 0 ? $cores : 1;
    }

    /** @return list<array{directory:string, used:int, total:int}> */
    protected function storage(): array
    {
        $disks = [];

        foreach ((array) $this->recorderConfig('directories', [base_path()]) as $directory) {
            $total = @disk_total_space((string) $directory);
            $free = @disk_free_space((string) $directory);

            if (is_float($total) && $total > 0 && is_float($free)) {
                $disks[] = [
                    'directory' => (string) $directory,
                    'total' => (int) round($total / 1_048_576),
                    'used' => (int) round(($total - $free) / 1_048_576),
                ];
            }
        }

        return $disks;
    }

    protected function run(string $command): string
    {
        if (! function_exists('shell_exec')) {
            return '';
        }

        return trim((string) @shell_exec($command));
    }

    protected function powershell(string $script): string
    {
        $command = sprintf('powershell -NoProfile -NonInteractive -Command "%s"', str_replace('"', '\\"', $script));

        return $this->run($command);
    }
}
