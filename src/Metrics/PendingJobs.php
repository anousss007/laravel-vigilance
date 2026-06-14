<?php

namespace Vigilance\Metrics;

use Illuminate\Support\Facades\DB;

/**
 * Reads the LIVE contents of a queue backend (jobs waiting to be processed) —
 * complementing the captured "queued" runs with the real backend state.
 * Implemented for the database driver; other drivers return null (the queue
 * backend can't be browsed generically without driver-specific APIs).
 */
class PendingJobs
{
    /**
     * @return ?list<array{id:int, queue:string, name:string, attempts:int, delayed:bool, reserved:bool}>
     */
    public function for(string $connection, ?string $queue = null, int $limit = 100): ?array
    {
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return null;
        }

        try {
            $rows = DB::connection(config("queue.connections.{$connection}.connection"))
                ->table((string) config("queue.connections.{$connection}.table", 'jobs'))
                ->when($queue !== null, fn ($q) => $q->where('queue', $queue))
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'payload']);
        } catch (\Throwable) {
            return null;
        }

        $now = time();

        return $rows->map(function ($row) use ($now): array {
            $payload = json_decode((string) $row->payload, true);
            $payload = is_array($payload) ? $payload : [];

            return [
                'id' => (int) $row->id,
                'queue' => (string) $row->queue,
                'name' => (string) ($payload['displayName'] ?? $payload['data']['commandName'] ?? 'job'),
                'attempts' => (int) $row->attempts,
                'delayed' => $row->available_at !== null && (int) $row->available_at > $now,
                'reserved' => $row->reserved_at !== null,
            ];
        })->all();
    }
}
