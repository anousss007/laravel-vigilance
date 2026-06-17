<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;

#[Description('Get one error issue in full: status, exception class/message, occurrence count, release info, the captured sample stacktrace and context, and the most recent runs that triggered it (with their parameters and exception). Pass the issue id from the "issues" or "overview" tool.')]
#[IsReadOnly]
class IssueTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The issue id.')
                ->required(),
            'runs' => $schema->integer()
                ->description('How many recent triggering runs to include (default 10, capped by the server).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = $request->integer('id');
        $issue = FailureGroup::query()->find($id);

        if ($issue === null) {
            return Response::error("Issue [{$id}] not found.");
        }

        $runLimit = $this->resolveLimit($request->integer('runs') ?: 10);

        $runs = Run::query()
            ->where('failure_group_id', $issue->getKey())
            ->orderByDesc('id')
            ->limit($runLimit)
            ->get([
                'id', 'type', 'status', 'name', 'queue', 'connection_name',
                'parameters', 'exception_class', 'exception_message', 'duration_ms', 'finished_at',
            ]);

        return $this->json([
            'id' => $issue->id,
            'status' => $issue->status(),
            'type' => $issue->type,
            'source' => $issue->source,
            'signature' => $issue->signature,
            'exception_class' => $issue->exception_class,
            'message' => $this->truncate($issue->message),
            'occurrences' => $issue->occurrences,
            'priority' => $issue->priority,
            'assignee' => $issue->assignee,
            'first_release' => $issue->first_release,
            'regressed_release' => $issue->regressed_release,
            'regressed' => $issue->isRegressed(),
            'first_seen_at' => $this->date($issue->first_seen_at),
            'last_seen_at' => $this->date($issue->last_seen_at),
            'acknowledged_at' => $this->date($issue->acknowledged_at),
            'resolved_at' => $this->date($issue->resolved_at),
            'muted_until' => $this->date($issue->muted_until),
            'context' => $this->redact($issue->context),
            'sample' => $this->truncate($issue->sample),
            'recent_runs' => $runs->map(fn (Run $r): array => [
                'id' => $r->id,
                'type' => $r->type->value,
                'status' => $r->status->value,
                'name' => $r->name,
                'queue' => $r->queue,
                'connection' => $r->connection_name,
                'parameters' => $this->redact($r->parameters),
                'exception_class' => $r->exception_class,
                'exception_message' => $this->truncate($r->exception_message),
                'duration_ms' => $r->duration_ms,
                'finished_at' => $this->date($r->finished_at),
            ])->all(),
        ]);
    }
}
