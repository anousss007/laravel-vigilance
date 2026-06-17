<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Apm\Contracts\Storage;

#[Description('APM exception counts over a window, grouped by exception class + code location, most frequent first. For the full grouped error inbox with stacktraces and triggering runs, use "issues".')]
#[IsReadOnly]
class ExceptionsTool extends Tool
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
                ->description('Max exception groups to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, Storage $storage): Response
    {
        $window = (string) ($request->get('window') ?: '24h');
        $limit = $this->resolveLimit($request->integer('limit'));

        $rows = $storage->aggregate('exception', ['max', 'count'], $this->interval($window), orderBy: 'count', limit: $limit)
            ->map(function ($row): array {
                $decoded = json_decode((string) $row->key, true);
                $lastSeen = (int) $row->max;

                return [
                    'class' => is_array($decoded) ? ($decoded['class'] ?? null) : null,
                    'location' => is_array($decoded) ? ($decoded['location'] ?? null) : null,
                    'count' => (int) $row->count,
                    'last_seen' => $lastSeen > 0 ? date('c', $lastSeen) : null,
                ];
            })->all();

        return $this->json([
            'window' => $window,
            'count' => count($rows),
            'exceptions' => $rows,
        ]);
    }
}
