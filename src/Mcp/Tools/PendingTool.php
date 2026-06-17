<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\PendingJobs;
use Vigilance\Models\Run;

#[Description('Jobs currently waiting in the queue backend (database-driver connections), per connection: id, queue, job name, attempts, and delayed/reserved state. The real live backlog, not just captured runs. Non-database connections are skipped.')]
#[IsReadOnly]
class PendingTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max pending jobs to return per connection (capped by the server).'),
        ];
    }

    public function handle(Request $request, PendingJobs $pending): Response
    {
        $limit = $this->resolveLimit($request->integer('limit'));

        $connections = Run::query()
            ->whereNotNull('connection_name')
            ->where('created_at', '>=', now()->subDay())
            ->distinct()
            ->pluck('connection_name');

        $out = [];

        foreach ($connections as $connection) {
            $jobs = $pending->for((string) $connection, null, $limit);

            if ($jobs === null) {
                continue;
            }

            $out[] = [
                'connection' => (string) $connection,
                'pending_count' => count($jobs),
                'jobs' => $jobs,
            ];
        }

        return $this->json([
            'connections' => $out,
        ]);
    }
}
