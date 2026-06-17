<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Apm\Contracts\Storage;

#[Description('Top users by activity over a window: how many requests and queued jobs are attributed to each user. Requires the per-user APM recorders to be enabled.')]
#[IsReadOnly]
class UsageTool extends Tool
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
                ->description('Max users to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, Storage $storage): Response
    {
        $window = (string) ($request->get('window') ?: '24h');
        $interval = $this->interval($window);
        $limit = $this->resolveLimit($request->integer('limit'));

        $jobs = $storage->aggregate('user_job', ['count'], $interval, orderBy: 'count', limit: 200)->keyBy('key');
        $names = $storage->values('user');

        $users = $storage->aggregate('user_request', ['count'], $interval, orderBy: 'count', limit: $limit)
            ->map(function ($row) use ($jobs, $names): array {
                $id = (string) $row->key;
                $info = isset($names[$id]) ? json_decode((string) $names[$id]->value, true) : null;

                return [
                    'user_id' => $id,
                    'name' => is_array($info) ? ($info['name'] ?? null) : null,
                    'requests' => (int) $row->count,
                    'jobs' => isset($jobs[$id]) ? (int) $jobs[$id]->count : 0,
                ];
            })->values()->all();

        return $this->json([
            'window' => $window,
            'count' => count($users),
            'users' => $users,
        ]);
    }
}
