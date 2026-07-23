<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\Stats;
use Vigilance\Metrics\Workload;

#[Description('Worker workload overview: system load average (1/5/15m) and a per-job-class breakdown over a window (runs, failures, fail %, avg/max runtime, avg memory/CPU). Pairs with "queues" (per-queue depth/throughput) for the full Workload dashboard view.')]
#[IsReadOnly]
class WorkloadTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'window' => $schema->string()
                ->description('Time window for the job-class breakdown: 15m, 1h, 24h, 7d. Defaults to 24h.'),
        ];
    }

    public function handle(Request $request, Workload $workload, Stats $stats): Response
    {
        $window = (string) ($request->get('window') ?: '24h');
        $limit = $this->resolveLimit();

        return $this->json([
            'system_load' => $workload->load(),
            'window' => $window,
            'job_classes' => array_slice($stats->byJobClass($window, $limit), 0, $limit),
        ]);
    }
}
