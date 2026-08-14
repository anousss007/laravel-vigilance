<?php

namespace Vigilance\Notifications\Rules;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Watches Vigilance's own collection pipeline.
 *
 * The worst failure mode of a monitoring tool is dying quietly: no heartbeat,
 * no telemetry, and a dashboard full of green because nothing is being written.
 * Silence and health look identical from the outside, so something has to say
 * which one it is.
 *
 * What this rule *cannot* cover: it runs from vigilance:snapshot, which is also
 * what evaluates every alert. If the snapshotter itself stops, nothing here
 * ever executes again — a dead-man's switch cannot live inside the process it
 * watches. That gap is covered from the outside instead: every run records a
 * 'vigilance' heartbeat (see SnapshotCommand) which `vigilance:doctor` and the
 * dashboard surface, so an external uptime check has something to read.
 */
class MonitoringHealthRule implements AlertRule
{
    public function __construct(protected Storage $storage) {}

    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.monitoring_health.enabled', true)) {
            return;
        }

        yield from $this->staleServers();
        yield from $this->stalledIngest();
    }

    /**
     * A server that used to report and has gone quiet: `vigilance:check` is no
     * longer running there, so all of its CPU/memory/disk data is now frozen
     * and every resource alert for it is silently dead.
     *
     * @return iterable<Alert>
     */
    protected function staleServers(): iterable
    {
        $staleAfter = max(60, (int) config('vigilance.alerts.rules.monitoring_health.heartbeat_stale_after', 600));
        $cutoff = time() - $staleAfter;

        foreach ($this->storage->values('system') as $slug => $row) {
            $data = json_decode((string) ($row->value ?? ''), true);

            if (! is_array($data)) {
                continue;
            }

            $updatedAt = (int) ($data['updated_at'] ?? 0);

            if ($updatedAt === 0 || $updatedAt >= $cutoff) {
                continue;
            }

            $name = (string) ($data['name'] ?? $slug);
            $minutes = (int) round((time() - $updatedAt) / 60);

            yield new Alert(
                key: 'monitoring_heartbeat:'.$name,
                title: 'Server stopped reporting to Vigilance',
                message: "[{$name}] last sent a heartbeat {$minutes} minute(s) ago. Its CPU, memory and "
                    .'disk figures are frozen and its resource alerts can no longer fire — check that '
                    .'`vigilance:check` is still scheduled and running on that host.',
                level: 'warning',
            );
        }
    }

    /**
     * APM is enabled and the app is serving traffic, yet nothing has been
     * written for a while — the ingest path is broken (a dead
     * `vigilance:apm-work`, an unreachable Redis, a failing connection).
     *
     * @return iterable<Alert>
     */
    protected function stalledIngest(): iterable
    {
        if (! config('vigilance.apm.enabled', true)) {
            return;
        }

        $window = max(1, (int) config('vigilance.alerts.rules.monitoring_health.ingest_stalled_after', 15));

        $lastWrite = $this->lastEntryWrittenAt();

        if ($lastWrite === null) {
            return; // Nothing has ever been written, or it has all been pruned.
        }

        $idleMinutes = (int) floor((time() - $lastWrite) / 60);

        if ($idleMinutes < $window) {
            return; // Still writing.
        }

        // An app nobody is using also stops producing telemetry, and reporting
        // that as an outage is the classic false positive here. Only call it
        // broken if there WAS traffic over the past hour.
        $recent = $this->storage->aggregateTotal(
            ['request', 'exception', 'cache_hit', 'slow_query'],
            'count',
            CarbonInterval::hour(),
        );

        if ($recent <= 0) {
            return;
        }

        yield new Alert(
            key: 'monitoring_ingest_stalled',
            title: 'Vigilance stopped recording telemetry',
            message: "Nothing has been written for {$idleMinutes} minute(s), although "
                .((int) $recent).' entries were recorded in the last hour. The ingest path looks '
                .'broken — check the configured ingest driver, and `vigilance:apm-work` if you are '
                .'on the redis driver.',
            level: 'critical',
        );
    }

    /**
     * When telemetry was last actually persisted.
     *
     * Read from the raw entries table rather than through aggregateTotal():
     * the rolled buckets only exist for the canonical 1h/6h/24h/7d windows, so
     * asking that layer about "the last 15 minutes" silently answers zero.
     */
    protected function lastEntryWrittenAt(): ?int
    {
        $connection = config('vigilance.storage.connection') ?: config('database.default');

        $latest = DB::connection($connection)
            ->table('vigilance_entries')
            ->max('timestamp');

        return $latest === null ? null : (int) $latest;
    }
}
