<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\Exceptions\CannotRetry;
use Vigilance\Control\JobRetrier;

#[Description('Retry a failed queued job by run id. Re-dispatches the original job (attributed to the MCP actor) and records it in the audit log. Only failed job runs can be retried. Requires writes to be enabled (vigilance.mcp.allow_writes).')]
#[IsDestructive]
class RetryRunTool extends Tool
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
                ->description('The failed run id to retry.')
                ->required(),
        ];
    }

    public function handle(Request $request, JobRetrier $retrier): Response
    {
        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        $id = $request->integer('id');

        try {
            $retrier->retry($id, $this->actor($request));
        } catch (CannotRetry $e) {
            return Response::error($e->getMessage());
        }

        return $this->json(['ok' => true, 'retried_run_id' => $id]);
    }
}
