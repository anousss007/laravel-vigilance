<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Models\Run;
use Vigilance\Support\Like;

#[Description('List captured runs (queued jobs, artisan commands, scheduled tasks) with status, queue, duration and failure info. Filter by type, status or queue, or search the name. Use the "run" tool for one run\'s parameters, output and full exception.')]
#[IsReadOnly]
class RunsTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->enum(['job', 'command', 'schedule', 'all'])
                ->description('Run type. Defaults to "all".'),
            'status' => $schema->string()
                ->enum(['queued', 'running', 'succeeded', 'failed', 'released', 'skipped', 'all'])
                ->description('Run status. Defaults to "all". Use "failed" to find problems.'),
            'queue' => $schema->string()
                ->description('Filter by queue name.'),
            'q' => $schema->string()
                ->description('Search the run name / display name (contains, case-insensitive).'),
            'limit' => $schema->integer()
                ->description('Max runs to return (capped by the server).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $type = (string) ($request->get('type') ?: 'all');
        $status = (string) ($request->get('status') ?: 'all');
        $queue = trim((string) $request->get('queue'));
        $q = trim((string) $request->get('q'));
        $limit = $this->resolveLimit($request->integer('limit'));

        $runs = Run::query()
            ->when($type !== 'all', fn ($query) => $query->where('type', $type))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($queue !== '', fn ($query) => $query->where('queue', $queue))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q): void {
                $term = Like::contains($q);
                $inner->whereRaw('name like ? escape ?', [$term, Like::ESCAPE])
                    ->orWhereRaw('display_name like ? escape ?', [$term, Like::ESCAPE]);
            }))
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id', 'type', 'status', 'name', 'display_name', 'queue', 'connection_name',
                'attempt', 'wait_ms', 'duration_ms', 'exception_class', 'exception_message',
                'failure_group_id', 'via', 'caused_by', 'finished_at',
            ]);

        return $this->json([
            'count' => $runs->count(),
            'runs' => $runs->map(fn (Run $r): array => [
                'id' => $r->id,
                'type' => $r->type->value,
                'status' => $r->status->value,
                'name' => $r->name,
                'display_name' => $r->display_name,
                'queue' => $r->queue,
                'connection' => $r->connection_name,
                'attempt' => $r->attempt,
                'wait_ms' => $r->wait_ms,
                'duration_ms' => $r->duration_ms,
                'exception_class' => $r->exception_class,
                'exception_message' => $this->truncate($r->exception_message),
                'issue_id' => $r->failure_group_id,
                'via' => $r->via,
                'caused_by' => $r->caused_by,
                'finished_at' => $this->date($r->finished_at),
            ])->all(),
        ]);
    }
}
