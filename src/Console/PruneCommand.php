<?php

namespace Vigilance\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Vigilance\Apm\Contracts\Storage as ApmStorage;
use Vigilance\Contracts\MetricsRepository;
use Vigilance\Enums\RunStatus;
use Vigilance\Models\AuditEntry;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;
use Vigilance\Models\RunTag;
use Vigilance\Tracing\Contracts\TraceStorage;

class PruneCommand extends Command
{
    protected $signature = 'vigilance:prune
        {--days= : Delete non-failed runs older than this many days (defaults to config)}
        {--failed-days= : Delete failed runs older than this many days (defaults to config)}
        {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Prune old Vigilance runs and trim metric snapshots to keep tables bounded.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('vigilance.retention.days', 14));
        $failedDays = (int) ($this->option('failed-days') ?? config('vigilance.retention.failed_days', 30));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = Carbon::now()->subDays($days);
        $failedCutoff = Carbon::now()->subDays($failedDays);

        $nonFailed = Run::query()
            ->where('created_at', '<', $cutoff)
            ->where('status', '!=', RunStatus::Failed->value);

        $failed = Run::query()
            ->where('created_at', '<', $failedCutoff)
            ->where('status', RunStatus::Failed->value);

        if ($dryRun) {
            $this->table(['What', 'Rows', 'Older than'], [
                ['non-failed runs', $nonFailed->count(), $cutoff->toDateTimeString()],
                ['failed runs', $failed->count(), $failedCutoff->toDateTimeString()],
            ]);

            return self::SUCCESS;
        }

        $deletedNonFailed = $this->chunkDelete($nonFailed);
        $deletedFailed = $this->chunkDelete($failed);

        RunTag::query()->where('created_at', '<', $cutoff)->delete();

        // Trim the audit log on the same non-failed retention window.
        AuditEntry::query()->where('created_at', '<', $cutoff)->delete();

        // Drop resolved failure groups that have no surviving runs.
        FailureGroup::query()
            ->whereNotNull('resolved_at')
            ->whereDoesntHave('runs')
            ->where('last_seen_at', '<', $failedCutoff)
            ->delete();

        app(MetricsRepository::class)->trim((int) config('vigilance.retention.snapshots', 60));

        // Also bound the APM telemetry tables (entries / aggregates / values).
        if (config('vigilance.apm.enabled', true)) {
            app(ApmStorage::class)->trim();
        }

        // …and the tracing tables (traces / spans), which keep a short window.
        if (config('vigilance.tracing.enabled', false)) {
            app(TraceStorage::class)->trim();
        }

        $this->info("Pruned {$deletedNonFailed} run(s) and {$deletedFailed} failed run(s).");

        return self::SUCCESS;
    }

    protected function chunkDelete(Builder $query): int
    {
        $total = 0;

        do {
            $ids = $query->clone()->limit(1000)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += Run::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        return $total;
    }
}
