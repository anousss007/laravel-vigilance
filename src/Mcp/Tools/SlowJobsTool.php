<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Apm\Contracts\Storage;

#[Description('The slowest queued jobs over a window, keyed by job class, with run count and max/avg duration (ms). Sync jobs are excluded. The "which job is slow" view.')]
#[IsReadOnly]
class SlowJobsTool extends Tool
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
                ->description('Max jobs to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, Storage $storage): Response
    {
        $window = (string) ($request->get('window') ?: '24h');
        $limit = $this->resolveLimit($request->integer('limit'));

        $jobs = $storage->aggregate('slow_job', ['count', 'max', 'avg'], $this->interval($window), orderBy: 'max', limit: $limit)
            ->map(fn ($row): array => [
                'job' => (string) $row->key,
                'count' => (int) $row->count,
                'max_ms' => (int) $row->max,
                'avg_ms' => (int) round((float) $row->avg),
            ])->all();

        return $this->json([
            'window' => $window,
            'count' => count($jobs),
            'jobs' => $jobs,
        ]);
    }
}
