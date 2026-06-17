<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\Stats;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;

#[Description('High-level health summary for the application: run counts and success rate over a window, the top unresolved error issues, the most recent failures, and the slowest recent runs. Call this first to decide where to look next.')]
#[IsReadOnly]
class OverviewTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'window' => $schema->string()
                ->description('Time window for the counts, e.g. "1h", "24h", "7d". Defaults to "24h".'),
        ];
    }

    public function handle(Request $request, Stats $stats): Response
    {
        $window = (string) ($request->get('window') ?: '24h');

        return $this->json([
            'window' => $window,
            'counts' => $stats->counts($window),
            'top_issues' => $stats->topFailing(5)->map(fn (FailureGroup $g): array => [
                'id' => $g->id,
                'name' => $g->name,
                'exception_class' => $g->exception_class,
                'message' => $this->truncate($g->message),
                'occurrences' => $g->occurrences,
                'last_seen_at' => $this->date($g->last_seen_at),
            ])->all(),
            'recent_failures' => $stats->recentFailures(10)->map(fn (Run $r): array => [
                'id' => $r->id,
                'type' => $r->type->value,
                'name' => $r->name,
                'queue' => $r->queue,
                'exception_class' => $r->exception_class,
                'exception_message' => $this->truncate($r->exception_message),
                'issue_id' => $r->failure_group_id,
                'finished_at' => $this->date($r->finished_at),
            ])->all(),
            'slowest_runs' => $stats->slowest(5)->map(fn (Run $r): array => [
                'id' => $r->id,
                'type' => $r->type->value,
                'name' => $r->name,
                'duration_ms' => $r->duration_ms,
                'finished_at' => $this->date($r->finished_at),
            ])->all(),
        ]);
    }
}
