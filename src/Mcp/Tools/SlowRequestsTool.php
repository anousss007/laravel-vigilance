<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Apm\Contracts\Storage;

#[Description('The slowest HTTP requests over a window (above the slow-request threshold), keyed by method + route path, with max duration (ms) and count. Complements "performance", which covers all routes.')]
#[IsReadOnly]
class SlowRequestsTool extends Tool
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
                ->description('Max requests to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, Storage $storage): Response
    {
        $window = (string) ($request->get('window') ?: '24h');
        $limit = $this->resolveLimit($request->integer('limit'));

        $rows = $storage->aggregate('slow_request', ['max', 'count'], $this->interval($window), orderBy: 'max', limit: $limit)
            ->map(function ($row): array {
                $decoded = json_decode((string) $row->key, true);

                return [
                    'method' => is_array($decoded) ? (string) ($decoded[0] ?? '') : '',
                    'path' => is_array($decoded) ? (string) ($decoded[1] ?? $row->key) : (string) $row->key,
                    'max_ms' => (int) $row->max,
                    'count' => (int) $row->count,
                ];
            })->all();

        return $this->json([
            'window' => $window,
            'count' => count($rows),
            'requests' => $rows,
        ]);
    }
}
