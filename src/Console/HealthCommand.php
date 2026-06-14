<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;
use Vigilance\Apm\Apm;

/**
 * Pings the configured uptime URLs and records availability + response time as
 * APM metrics (the "Uptime" card). Schedule it (e.g. everyMinute) to build a
 * history. Self-hosted uptime checking — no external service required.
 */
class HealthCommand extends Command
{
    protected $signature = 'vigilance:health';

    protected $description = 'Ping the configured uptime URLs and record availability + latency.';

    public function handle(Apm $apm): int
    {
        $urls = array_values((array) config('vigilance.uptime.urls', []));

        if ($urls === []) {
            $this->components->warn('No uptime URLs configured (vigilance.uptime.urls).');

            return self::SUCCESS;
        }

        $timeout = (int) config('vigilance.uptime.timeout', 5);

        foreach ($urls as $url) {
            $url = (string) $url;
            $start = microtime(true);
            $up = false;
            $status = 0;

            try {
                $response = Http::timeout($timeout)->get($url);
                $status = $response->status();
                $up = $response->successful();
            } catch (Throwable) {
                // Treated as down.
            }

            $ms = (int) round((microtime(true) - $start) * 1000);
            $now = time();

            $apm->record('uptime_latency', $url, $ms, $now)->avg()->onlyBuckets();
            $apm->record('uptime_up', $url, $up ? 100 : 0, $now)->avg()->onlyBuckets();
            $apm->set('uptime', $url, (string) json_encode([
                'url' => $url,
                'up' => $up,
                'status' => $status,
                'latency_ms' => $ms,
                'checked_at' => $now,
            ]), $now);

            $this->components->{$up ? 'info' : 'error'}("{$url} — ".($up ? "up ({$status}, {$ms}ms)" : "DOWN ({$status})"));
        }

        $apm->ingest();

        return self::SUCCESS;
    }
}
