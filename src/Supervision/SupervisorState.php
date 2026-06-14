<?php

namespace Vigilance\Supervision;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Vigilance\Models\SupervisorRecord;
use Vigilance\Models\WorkerRecord;

/**
 * Persists the live state of supervisors and their workers (a heartbeat with
 * expiry, like Horizon's Redis repos but in DB tables so it's driver-agnostic).
 * The dashboard and `vigilance:status` read from here.
 */
class SupervisorState
{
    /**
     * Write/refresh a supervisor's heartbeat and the set of its current workers.
     *
     * @param  array<string, int>  $pools  process count per pool key
     * @param  list<array{pid: int, queue: string}>  $workers
     */
    public function heartbeat(SupervisorOptions $options, string $status, array $pools, array $workers): void
    {
        $now = Carbon::now();

        SupervisorRecord::query()->updateOrCreate(
            ['name' => $options->name],
            [
                'host' => gethostname() ?: null,
                'pid' => getmypid() ?: null,
                'status' => $status,
                'connection' => $options->connection,
                'queues' => implode(',', $options->queue),
                'balance' => $options->balance,
                'processes' => array_sum($pools),
                'pools' => $pools,
                'options' => $options->toArray(),
                'last_heartbeat_at' => $now,
            ],
        );

        WorkerRecord::query()->where('supervisor', $options->name)->delete();

        if ($workers !== []) {
            WorkerRecord::query()->insert(array_map(fn (array $w) => [
                'supervisor' => $options->name,
                'host' => gethostname() ?: null,
                'pid' => $w['pid'],
                'connection' => $options->connection,
                'queue' => $w['queue'],
                'status' => $status,
                'last_heartbeat_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $workers));
        }
    }

    public function forget(string $name): void
    {
        SupervisorRecord::query()->whereKey($name)->delete();
        WorkerRecord::query()->where('supervisor', $name)->delete();
    }

    /**
     * Remove supervisors (and their workers) that stopped heartbeating.
     */
    public function pruneExpired(int $seconds): void
    {
        $cutoff = Carbon::now()->subSeconds(max(1, $seconds));

        $stale = SupervisorRecord::query()
            ->where('last_heartbeat_at', '<', $cutoff)
            ->pluck('name');

        if ($stale->isNotEmpty()) {
            SupervisorRecord::query()->whereIn('name', $stale)->delete();
            WorkerRecord::query()->whereIn('supervisor', $stale)->delete();
        }
    }

    /**
     * Supervisors considered alive (heartbeat within the expiry window).
     *
     * @return Collection<int, SupervisorRecord>
     */
    public function active(int $seconds): Collection
    {
        return SupervisorRecord::query()
            ->where('last_heartbeat_at', '>=', Carbon::now()->subSeconds(max(1, $seconds)))
            ->orderBy('name')
            ->get();
    }
}
