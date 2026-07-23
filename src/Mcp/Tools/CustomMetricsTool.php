<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\CustomMetrics;
use Vigilance\Metrics\CustomMetricStat;

#[Description('Custom business metrics recorded via Vigilance::increment() / gauge() / histogram() / timing(): counters (sum + event count), gauges (average + peak) and distributions (average, max and p50/p95/p99) over a window.')]
#[IsReadOnly]
class CustomMetricsTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'window' => $schema->string()
                ->description('Time window, e.g. "24h", "7d". Defaults to "24h".'),
        ];
    }

    public function handle(Request $request, CustomMetrics $metrics): Response
    {
        $window = (string) ($request->get('window') ?: '24h');

        $rows = $metrics->all($this->interval($window))
            ->map(fn (CustomMetricStat $m): array => array_filter([
                'name' => $m->name,
                'type' => $m->type,
                'value' => $m->value,
                'peak' => $m->peak,
                'p50' => $m->p50,
                'p95' => $m->p95,
                'p99' => $m->p99,
            ], fn ($v) => $v !== null))->all();

        return $this->json([
            'window' => $window,
            'count' => count($rows),
            'metrics' => $rows,
        ]);
    }
}
