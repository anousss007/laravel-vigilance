<?php

namespace Vigilance\Tracing\Storage;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Vigilance\Support\Like;
use Vigilance\Tracing\Contracts\TraceStorage;
use Vigilance\Tracing\Span;
use Vigilance\Tracing\Trace;

/**
 * Database-backed trace storage. Trace + spans are written in one transaction
 * with batched span inserts, on the configured Vigilance connection (point it
 * at a dedicated connection to keep trace writes off your primary database).
 */
class DatabaseTraceStorage implements TraceStorage
{
    /**
     * @param  array<string, mixed>  $trace
     */
    public function store(array $trace): void
    {
        $connection = $this->connection();
        $now = CarbonImmutable::now();

        $spanRows = [];
        foreach ($trace['spans'] as $span) {
            $spanRows[] = [
                'trace_id' => $trace['id'],
                'parent_id' => $span['parent'] ?? null,
                'type' => $span['type'],
                'label' => $span['label'],
                'start_us' => $span['offset'],
                'duration_us' => $span['duration'],
                'attributes' => (string) json_encode($span['attributes'] ?? []),
            ];
        }

        $connection->transaction(function () use ($connection, $trace, $spanRows, $now) {
            $connection->table('vigilance_traces')->insert([
                'id' => $trace['id'],
                'type' => $trace['type'],
                'name' => $trace['name'],
                'status' => $trace['status'],
                'duration_ms' => $trace['duration_ms'],
                'span_count' => $trace['span_count'],
                'dropped_spans' => $trace['dropped_spans'],
                'user_id' => $trace['user_id'] !== null ? (string) $trace['user_id'] : null,
                'started_at' => $trace['started_at'],
                'attributes' => (string) json_encode($trace['attributes']),
                'created_at' => $now,
            ]);

            foreach (array_chunk($spanRows, 500) as $chunk) {
                $connection->table('vigilance_spans')->insert($chunk);
            }
        });
    }

    public function recent(array $filters = [], int $limit = 50): Collection
    {
        $rows = $this->connection()
            ->table('vigilance_traces')
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['slow']), fn ($q) => $q->where('duration_ms', '>=', (int) $filters['slow']))
            ->when(
                isset($filters['q']) && $filters['q'] !== '',
                fn ($q) => $q->whereRaw('name like ? escape ?', [Like::contains((string) $filters['q']), Like::ESCAPE])
            )
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => $this->toTrace($row));
    }

    public function find(string $id): ?Trace
    {
        $row = $this->connection()->table('vigilance_traces')->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        $spans = $this->connection()
            ->table('vigilance_spans')
            ->where('trace_id', $id)
            ->orderBy('start_us')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => new Span(
                type: (string) $s->type,
                label: (string) $s->label,
                offsetUs: (int) $s->start_us,
                durationUs: (int) $s->duration_us,
                attributes: $this->decode($s->attributes),
                parentId: $s->parent_id !== null ? (string) $s->parent_id : null,
            ))
            ->all();

        return $this->toTrace($row, $spans);
    }

    public function trim(): void
    {
        $keep = config('vigilance.tracing.retention') ?? '72 hours';

        $before = CarbonImmutable::now()->subMilliseconds(
            (int) CarbonInterval::fromString((string) $keep)->totalMilliseconds
        )->getTimestamp();

        $connection = $this->connection();

        do {
            $ids = $connection->table('vigilance_traces')
                ->where('started_at', '<', $before)
                ->limit(1000)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $connection->table('vigilance_spans')->whereIn('trace_id', $ids)->delete();
            $connection->table('vigilance_traces')->whereIn('id', $ids)->delete();
        } while (count($ids) === 1000);
    }

    public function purge(): void
    {
        $this->connection()->table('vigilance_spans')->truncate();
        $this->connection()->table('vigilance_traces')->truncate();
    }

    /**
     * @param  list<Span>  $spans
     */
    protected function toTrace(object $row, array $spans = []): Trace
    {
        return new Trace(
            id: (string) $row->id,
            type: (string) $row->type,
            name: (string) $row->name,
            status: (string) $row->status,
            durationMs: (int) $row->duration_ms,
            spanCount: (int) $row->span_count,
            droppedSpans: (int) $row->dropped_spans,
            userId: $row->user_id !== null ? (string) $row->user_id : null,
            startedAt: (int) $row->started_at,
            attributes: $this->decode($row->attributes),
            spans: $spans,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(mixed $json): array
    {
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
