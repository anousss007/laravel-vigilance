<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\ReleaseHealth;
use Vigilance\Metrics\ReleaseHealthStatus;

#[Description('Deploy health: for each recent deployment, error rate / latency / throughput in the window after the deploy versus the equal window before, with a healthy / degraded / regressed verdict. Record deploys with vigilance:deploy.')]
#[IsReadOnly]
class ReleasesTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max deployments to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, ReleaseHealth $health): Response
    {
        $limit = $this->resolveLimit($request->integer('limit'));

        $rows = $health->recent($limit)
            ->map(fn (ReleaseHealthStatus $r): array => [
                'deployment_id' => $r->deploymentId,
                'label' => $r->label,
                'version' => $r->version,
                'commit' => $r->commit,
                'deployed_at' => date('c', $r->deployedAt),
                'verdict' => $r->verdict,
                'requests_before' => $r->requestsBefore,
                'requests_after' => $r->requestsAfter,
                'error_rate_before' => $r->errorRateBefore,
                'error_rate_after' => $r->errorRateAfter,
                'error_rate_delta' => $r->errorRateDelta(),
                'latency_before_ms' => $r->latencyBefore,
                'latency_after_ms' => $r->latencyAfter,
                'latency_delta_ms' => $r->latencyDelta(),
                'throughput_delta_pct' => $r->throughputDelta,
            ])->all();

        return $this->json([
            'count' => count($rows),
            'releases' => $rows,
        ]);
    }
}
