<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Tracing\Contracts\TraceStorage;
use Vigilance\Tracing\Trace;

#[Description('List recent request/job/command traces (a captured waterfall of everything that happened in one operation). Filter by type, status, slow-only, or a search term. Use the "trace" tool for one trace\'s spans and correlated logs. Requires tracing enabled (VIGILANCE_TRACING=true).')]
#[IsReadOnly]
class TracesTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->enum(['request', 'job', 'command', 'all'])
                ->description('Trace type. Defaults to "all".'),
            'status' => $schema->string()
                ->enum(['ok', 'error', 'all'])
                ->description('Trace status. Defaults to "all". Use "error" to find failures.'),
            'slow_only' => $schema->boolean()
                ->description('Only return traces slower than the configured slow threshold.'),
            'q' => $schema->string()
                ->description('Search the trace name (contains).'),
            'limit' => $schema->integer()
                ->description('Max traces to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, TraceStorage $traces): Response
    {
        $limit = $this->resolveLimit($request->integer('limit'));
        $type = (string) ($request->get('type') ?: 'all');
        $status = (string) ($request->get('status') ?: 'all');
        $q = trim((string) $request->get('q'));

        $filters = [];

        if ($type !== 'all') {
            $filters['type'] = $type;
        }

        if ($status !== 'all') {
            $filters['status'] = $status;
        }

        if ($request->boolean('slow_only')) {
            $filters['slow'] = (int) config('vigilance.tracing.slow_threshold', 1000);
        }

        if ($q !== '') {
            $filters['q'] = $q;
        }

        $rows = $traces->recent($filters, $limit)
            ->map(fn (Trace $t): array => [
                'id' => $t->id,
                'type' => $t->type,
                'name' => $t->name,
                'status' => $t->status,
                'duration_ms' => $t->durationMs,
                'span_count' => $t->spanCount,
                'started_at' => date('c', $t->startedAt),
            ])->all();

        return $this->json([
            'count' => count($rows),
            'traces' => $rows,
        ]);
    }
}
