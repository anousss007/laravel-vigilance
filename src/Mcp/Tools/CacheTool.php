<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Apm\Contracts\Storage;

#[Description('Cache hit-rate over a window (hits, misses, hit-rate percent) plus the most-missed cache keys.')]
#[IsReadOnly]
class CacheTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'window' => $schema->string()
                ->description('Time window, e.g. "1h", "24h". Defaults to "24h".'),
            'limit' => $schema->integer()
                ->description('Max missed keys to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, Storage $storage): Response
    {
        $window = (string) ($request->get('window') ?: '24h');
        $interval = $this->interval($window);
        $limit = $this->resolveLimit($request->integer('limit'));

        $hits = (int) $storage->aggregateTotal('cache_hit', 'count', $interval);
        $misses = (int) $storage->aggregateTotal('cache_miss', 'count', $interval);
        $total = $hits + $misses;

        $topMisses = $storage->aggregate('cache_miss', ['count'], $interval, orderBy: 'count', limit: $limit)
            ->map(fn ($row): array => [
                'key' => $this->truncate((string) $row->key),
                'count' => (int) $row->count,
            ])->all();

        return $this->json([
            'window' => $window,
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate_pct' => $total > 0 ? round($hits / $total * 100, 1) : null,
            'top_missed_keys' => $topMisses,
        ]);
    }
}
