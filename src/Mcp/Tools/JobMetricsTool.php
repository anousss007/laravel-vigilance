<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\Stats;

#[Description('Per-job-class performance over a window: run count, failures, fail-rate, average/max duration, and average memory/CPU. The "which job is eating the server" view.')]
#[IsReadOnly]
class JobMetricsTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'window' => $schema->string()
                ->description('Time window, e.g. "1h", "24h", "7d". Defaults to "24h".'),
            'limit' => $schema->integer()
                ->description('Max job classes to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, Stats $stats): Response
    {
        $window = (string) ($request->get('window') ?: '24h');
        $limit = $this->resolveLimit($request->integer('limit'));

        $jobs = $stats->byJobClass($window, $limit);

        return $this->json([
            'window' => $window,
            'count' => count($jobs),
            'jobs' => $jobs,
        ]);
    }
}
