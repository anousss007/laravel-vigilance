<?php

namespace Vigilance\Storage;

use Illuminate\Support\Collection;
use Vigilance\Contracts\MetricsRepository;
use Vigilance\Enums\RunStatus;
use Vigilance\Enums\RunType;
use Vigilance\Models\MetricSnapshot;
use Vigilance\Models\Run;

class DatabaseMetricsRepository implements MetricsRepository
{
    public function snapshot(\DateTimeInterface $since, \DateTimeInterface $until): void
    {
        $this->snapshotScope('job', 'name', $since, $until);
        $this->snapshotScope('queue', 'queue', $since, $until);
    }

    protected function snapshotScope(string $scopeType, string $column, \DateTimeInterface $since, \DateTimeInterface $until): void
    {
        $rows = Run::query()
            ->where('type', RunType::Job->value)
            ->whereNotNull($column)
            ->whereNotNull('finished_at')
            ->whereBetween('finished_at', [$since, $until])
            ->groupBy($column)
            ->selectRaw("{$column} as scope")
            ->selectRaw('count(*) as throughput')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failures', [RunStatus::Failed->value])
            ->selectRaw('avg(duration_ms) as runtime')
            ->selectRaw('avg(wait_ms) as waited')
            ->get();

        foreach ($rows as $row) {
            $waited = $row->getAttribute('waited');

            MetricSnapshot::query()->create([
                'scope_type' => $scopeType,
                'scope' => (string) $row->getAttribute('scope'),
                'throughput' => (int) $row->getAttribute('throughput'),
                'failures' => (int) $row->getAttribute('failures'),
                'runtime_avg_ms' => (int) round((float) $row->getAttribute('runtime')),
                'wait_avg_ms' => $waited !== null ? (int) round((float) $waited) : null,
                'measured_at' => $until,
            ]);
        }
    }

    public function series(string $scopeType, string $scope, int $limit = 60): Collection
    {
        $snapshots = MetricSnapshot::query()
            ->where('scope_type', $scopeType)
            ->where('scope', $scope)
            ->orderByDesc('measured_at')
            ->limit($limit)
            ->get()
            ->all();

        return $this->toPointCollection(array_reverse($snapshots));
    }

    /**
     * Wrap snapshot point rows as an opaque object collection, matching the
     * repository contract (callers treat each point as a read-only object).
     *
     * @param  array<int, object>  $points
     * @return Collection<int, object>
     */
    protected function toPointCollection(array $points): Collection
    {
        return new Collection($points);
    }

    public function trim(int $keep): void
    {
        $groups = MetricSnapshot::query()
            ->select('scope_type', 'scope')
            ->distinct()
            ->get();

        foreach ($groups as $group) {
            $keepIds = MetricSnapshot::query()
                ->where('scope_type', $group->scope_type)
                ->where('scope', $group->scope)
                ->orderByDesc('measured_at')
                ->limit($keep)
                ->pluck('id');

            MetricSnapshot::query()
                ->where('scope_type', $group->scope_type)
                ->where('scope', $group->scope)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }
    }
}
