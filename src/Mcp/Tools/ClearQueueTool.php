<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Control\QueueManager;

#[Description('Delete EVERY job waiting on a queue (purges the backlog for drivers that support clearing: database, redis, sqs — beanstalkd/sync are rejected). Destructive and irreversible. Requires BOTH manual control (VIGILANCE_CONTROL_ENABLED=true) AND MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true). Audited. To cancel specific jobs instead, use "cancel-pending".')]
#[IsDestructive]
class ClearQueueTool extends Tool
{
    public function shouldRegister(): bool
    {
        return $this->controlEnabled() && $this->writesEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection' => $schema->string()
                ->description('The queue connection to clear on (e.g. "redis").')
                ->required(),
            'queue' => $schema->string()
                ->description('The queue name to purge (e.g. "default").')
                ->required(),
        ];
    }

    public function handle(Request $request, QueueManager $queues): Response
    {
        if (! $this->controlEnabled() || ! $this->writesEnabled()) {
            return Response::error('Clearing a queue requires both vigilance.control.enabled and vigilance.mcp.allow_writes to be true.');
        }

        $connection = trim((string) $request->get('connection'));
        $queue = trim((string) $request->get('queue'));

        if ($connection === '' || $queue === '') {
            return Response::error('Both "connection" and "queue" are required.');
        }

        try {
            $deleted = $queues->clear($connection, $queue, $this->actor($request));
        } catch (NotAllowed $e) {
            return Response::error($e->getMessage());
        }

        return $this->json(['ok' => true, 'connection' => $connection, 'queue' => $queue, 'deleted' => $deleted]);
    }
}
