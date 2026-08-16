<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\SelfUsage;

/**
 * Named "footprint" rather than "usage": the "usage" tool already means
 * per-user activity (the Application Usage card). This one is about what
 * Vigilance itself stores.
 */
#[Description('What Vigilance itself is storing (the Usage page): rows per telemetry type, how many were written in the last 24h, the oldest row, the config knob that turns each one down, and whether pruning is keeping up. Use this to answer "why is the monitoring database growing" before touching sample rates.')]
#[IsReadOnly]
class FootprintTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request, SelfUsage $usage): Response
    {
        $tables = $usage->tables();
        $breaches = $usage->retentionBreaches();

        return $this->json([
            'connection' => config('vigilance.storage.connection') ?: config('database.default'),
            'total_rows' => array_sum(array_map(fn ($row) => $row['rows'] ?? 0, $tables)),
            'written_last_24h' => array_sum(array_map(fn ($row) => $row['last_day'] ?? 0, $tables)),
            // A null row count means the table was never migrated — an optional
            // feature nobody enabled, not an error.
            'tables' => array_map(fn (array $row) => [
                'table' => $row['table'],
                'telemetry' => $row['label'],
                'rows' => $row['rows'],
                'last_24h' => $row['last_day'],
                'oldest' => $row['oldest'],
                'turn_down_with' => $row['lever'],
            ], $tables),
            // Each entry already allows one prune interval of overhang, so a
            // non-empty list means rows outlasted a normal gap between prunes —
            // not that the prune failed. Saying otherwise here is how an agent
            // ends up reporting a broken scheduler that is running fine.
            'retention_breaches' => $breaches,
        ]);
    }
}
