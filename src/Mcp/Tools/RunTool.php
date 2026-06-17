<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Models\Run;

#[Description('Get one run in full: its parameters, tags, output, exit code, full exception/stacktrace, timing and resource usage, plus retry lineage. Pass the run id from the "runs", "overview" or "issue" tool.')]
#[IsReadOnly]
class RunTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The run id.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = $request->integer('id');
        $run = Run::query()->find($id);

        if ($run === null) {
            return Response::error("Run [{$id}] not found.");
        }

        return $this->json([
            'id' => $run->id,
            'uuid' => $run->uuid,
            'type' => $run->type->value,
            'status' => $run->status->value,
            'name' => $run->name,
            'display_name' => $run->display_name,
            'queue' => $run->queue,
            'connection' => $run->connection_name,
            'attempt' => $run->attempt,
            'via' => $run->via,
            'caused_by' => $run->caused_by,
            'tags' => $run->tags,
            'parameters' => $this->redact($run->parameters),
            'output' => $this->truncate($run->output),
            'exit_code' => $run->exit_code,
            'exception_class' => $run->exception_class,
            'exception_message' => $this->truncate($run->exception_message),
            'exception' => $this->truncate($run->exception),
            'issue_id' => $run->failure_group_id,
            'batch_id' => $run->batch_id,
            'retry_of' => $run->retry_of,
            'retries' => $run->retries()->count(),
            'memory_peak' => $run->memory_peak,
            'cpu_time_ms' => $run->cpu_time_ms,
            'wait_ms' => $run->wait_ms,
            'duration_ms' => $run->duration_ms,
            'queued_at' => $this->date($run->queued_at),
            'started_at' => $this->date($run->started_at),
            'finished_at' => $this->date($run->finished_at),
            'created_at' => $this->date($run->created_at),
        ]);
    }
}
