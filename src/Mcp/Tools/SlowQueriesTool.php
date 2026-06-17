<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Apm\Contracts\Storage;

#[Description('The slowest database queries over a window, keyed by SQL plus the application location that issued them, with how many times each ran and its max/avg duration (ms). The "which query is slow" view.')]
#[IsReadOnly]
class SlowQueriesTool extends Tool
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
                ->description('Max queries to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, Storage $storage): Response
    {
        $window = (string) ($request->get('window') ?: '24h');
        $limit = $this->resolveLimit($request->integer('limit'));

        $queries = $storage->aggregate('slow_query', ['count', 'max', 'avg'], $this->interval($window), orderBy: 'max', limit: $limit)
            ->map(function ($row): array {
                $decoded = json_decode((string) $row->key, true);
                $sql = is_array($decoded) ? (string) ($decoded['sql'] ?? '') : (string) $row->key;
                $location = is_array($decoded) ? ($decoded['location'] ?? null) : null;

                return [
                    'sql' => $this->truncate($sql),
                    'location' => $location,
                    'count' => (int) $row->count,
                    'max_ms' => (int) $row->max,
                    'avg_ms' => (int) round((float) $row->avg),
                ];
            })->all();

        return $this->json([
            'window' => $window,
            'count' => count($queries),
            'queries' => $queries,
        ]);
    }
}
