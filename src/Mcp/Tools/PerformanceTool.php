<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\RoutePerformance;
use Vigilance\Metrics\RouteStat;

#[Description('HTTP route performance over a window: throughput, error rate, Apdex, and exact p50/p95/p99 latency per route. The "what is slow or erroring" view for web requests.')]
#[IsReadOnly]
class PerformanceTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'window' => $schema->string()
                ->description('Time window, e.g. "1h", "24h", "7d". Defaults to "24h".'),
            'sort' => $schema->string()
                ->enum(['p95', 'p99', 'avg', 'max', 'count', 'errors', 'error_rate'])
                ->description('Order routes by this field, descending. Defaults to "p95".'),
            'limit' => $schema->integer()
                ->description('Max routes to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, RoutePerformance $performance): Response
    {
        $window = (string) ($request->get('window') ?: '24h');
        $limit = $this->resolveLimit($request->integer('limit'));
        $sort = (string) ($request->get('sort') ?: 'p95');

        $rows = $performance->forInterval($this->interval($window), $limit)
            ->map(fn (RouteStat $s): array => [
                'method' => $s->method,
                'path' => $s->path,
                'count' => $s->count,
                'errors' => $s->errors,
                'error_rate' => $s->error_rate,
                'apdex' => $s->apdex,
                'avg_ms' => $s->avg,
                'max_ms' => $s->max,
                'p50_ms' => $s->p50,
                'p95_ms' => $s->p95,
                'p99_ms' => $s->p99,
            ])->all();

        $field = [
            'p95' => 'p95_ms', 'p99' => 'p99_ms', 'avg' => 'avg_ms', 'max' => 'max_ms',
            'count' => 'count', 'errors' => 'errors', 'error_rate' => 'error_rate',
        ][$sort] ?? 'p95_ms';

        usort($rows, fn (array $a, array $b): int => ($b[$field] ?? -1) <=> ($a[$field] ?? -1));

        return $this->json([
            'window' => $window,
            'sort' => $sort,
            'count' => count($rows),
            'routes' => $rows,
        ]);
    }
}
