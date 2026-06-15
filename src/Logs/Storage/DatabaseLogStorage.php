<?php

namespace Vigilance\Logs\Storage;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Vigilance\Logs\Contracts\LogStorage;
use Vigilance\Logs\LogEntry;
use Vigilance\Support\Like;

/**
 * Database-backed log storage. Writes happen as one batched insert per flush on
 * the configured Vigilance connection (point it at a dedicated connection to
 * keep log writes off your primary database).
 */
class DatabaseLogStorage implements LogStorage
{
    public function store(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $connection = $this->connection();

        foreach (array_chunk($rows, 500) as $chunk) {
            $connection->table('vigilance_logs')->insert($chunk);
        }
    }

    public function search(array $filters = [], int $limit = 100): Collection
    {
        $rows = $this->connection()
            ->table('vigilance_logs')
            ->when(isset($filters['min_level']), fn ($q) => $q->where('level_value', '>=', (int) $filters['min_level']))
            ->when(isset($filters['level']) && $filters['level'] !== '', fn ($q) => $q->where('level', $filters['level']))
            ->when(isset($filters['channel']) && $filters['channel'] !== '', fn ($q) => $q->where('channel', $filters['channel']))
            ->when(isset($filters['trace_id']) && $filters['trace_id'] !== '', fn ($q) => $q->where('trace_id', $filters['trace_id']))
            ->when(
                isset($filters['q']) && $filters['q'] !== '',
                fn ($q) => $q->whereRaw('message like ? escape ?', [Like::contains((string) $filters['q']), Like::ESCAPE])
            )
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => $this->toEntry($row));
    }

    public function forTrace(string $traceId, int $limit = 200): Collection
    {
        return $this->connection()
            ->table('vigilance_logs')
            ->where('trace_id', $traceId)
            ->orderBy('logged_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->toEntry($row));
    }

    public function channels(int $limit = 50): array
    {
        return $this->connection()
            ->table('vigilance_logs')
            ->whereNotNull('channel')
            ->distinct()
            ->orderBy('channel')
            ->limit($limit)
            ->pluck('channel')
            ->map(fn ($c) => (string) $c)
            ->all();
    }

    public function trim(): void
    {
        $keep = config('vigilance.logs.retention') ?? '72 hours';

        $before = CarbonImmutable::now()->subMilliseconds(
            (int) CarbonInterval::fromString((string) $keep)->totalMilliseconds
        )->getTimestamp();

        $connection = $this->connection();

        do {
            $deleted = $connection->table('vigilance_logs')
                ->where('logged_at', '<', $before)
                ->limit(1000)
                ->delete();
        } while ($deleted === 1000);
    }

    public function purge(): void
    {
        $this->connection()->table('vigilance_logs')->truncate();
    }

    protected function toEntry(object $row): LogEntry
    {
        return new LogEntry(
            id: (int) $row->id,
            level: (string) $row->level,
            levelValue: (int) $row->level_value,
            message: (string) $row->message,
            channel: $row->channel !== null ? (string) $row->channel : null,
            traceId: $row->trace_id !== null ? (string) $row->trace_id : null,
            loggedAt: (int) $row->logged_at,
            context: $this->decode($row->context),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(mixed $json): array
    {
        if ($json === null) {
            return [];
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function connection(): Connection
    {
        return DB::connection(
            config('vigilance.storage.connection') ?: config('database.default')
        );
    }
}
