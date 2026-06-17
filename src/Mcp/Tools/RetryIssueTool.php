<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\JobRetrier;
use Vigilance\Models\FailureGroup;

#[Description('Retry every failed job in an error issue (failure group), then resolve the issue. Returns how many jobs were retried versus skipped (e.g. non-retryable). Requires writes to be enabled (vigilance.mcp.allow_writes). Audit-logged.')]
#[IsDestructive]
class RetryIssueTool extends Tool
{
    public function shouldRegister(): bool
    {
        return $this->writesEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The issue (failure group) id whose failed jobs to retry.')
                ->required(),
        ];
    }

    public function handle(Request $request, JobRetrier $retrier): Response
    {
        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        $id = $request->integer('id');
        $issue = FailureGroup::query()->find($id);

        if ($issue === null) {
            return Response::error("Issue [{$id}] not found.");
        }

        $result = $retrier->retryGroup($id, $this->actor($request));

        return $this->json([
            'ok' => true,
            'issue_id' => $id,
            'retried' => $result['retried'],
            'skipped' => $result['skipped'],
            'status' => 'resolved',
        ]);
    }
}
