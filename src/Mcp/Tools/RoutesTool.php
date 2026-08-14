<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\RoutePerformance;
use Vigilance\Metrics\RouteStat;

#[Description('Per-route HTTP performance over a window: request count, error count/rate, Apdex, average/max latency and p50/p95/p99, plus what each page costs — queries per request, database time, peak memory and Eloquent models hydrated. The ranked route table from the dashboard (complements "slow-requests"/"slow-http" which surface individual slow calls). Window like 15m, 1h, 24h, 7d.')]
#[IsReadOnly]
class RoutesTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'window' => $schema->string()
                ->description('Time window: 15m, 1h, 24h, 7d, 2w. Defaults to 1h.'),
            'limit' => $schema->integer()
                ->description('Max routes to return (ranked by request count).'),
        ];
    }

    public function handle(Request $request, RoutePerformance $routes): Response
    {
        $window = (string) ($request->get('window') ?: '1h');
        $limit = $this->resolveLimit($request->integer('limit') ?: null);

        $rows = $routes->forInterval($this->interval($window), $limit);

        return $this->json([
            'window' => $window,
            'count' => $rows->count(),
            'routes' => $rows->map(fn (RouteStat $r): array => [
                'method' => $r->method,
                'path' => $r->path,
                'requests' => $r->count,
                'errors' => $r->errors,
                'error_rate' => $r->error_rate,
                'apdex' => $r->apdex,
                'avg_ms' => $r->avg,
                'max_ms' => $r->max,
                'p50_ms' => $r->p50,
                'p95_ms' => $r->p95,
                'p99_ms' => $r->p99,
                // What the page costs, from the RequestProfile recorder — null
                // throughout when it is disabled.
                'avg_queries' => $r->queries_avg,
                'max_queries' => $r->queries_max,
                'avg_db_ms' => $r->db_ms_avg,
                'avg_memory_kb' => $r->memory_kb_avg,
                'max_memory_kb' => $r->memory_kb_max,
                'avg_models_hydrated' => $r->models_avg,
            ])->all(),
        ]);
    }
}
