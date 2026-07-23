<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\QueueManager;

#[Description('Resume a single paused queue so the supervisor starts pulling from it again. Requires MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true). Audited.')]
#[IsDestructive]
class ResumeQueueTool extends Tool
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
            'queue' => $schema->string()
                ->description('The queue name to resume.')
                ->required(),
            'connection' => $schema->string()
                ->description('The queue connection the queue lives on. Defaults to vigilance.defaults.connection.'),
        ];
    }

    public function handle(Request $request, QueueManager $queues): Response
    {
        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        $queue = trim((string) $request->get('queue'));

        if ($queue === '') {
            return Response::error('The "queue" parameter is required.');
        }

        $connection = trim((string) $request->get('connection'))
            ?: (string) config('vigilance.defaults.connection', 'database');

        $queues->resume($connection, $queue, $this->actor($request));

        return $this->json(['ok' => true, 'connection' => $connection, 'queue' => $queue, 'status' => 'resumed']);
    }
}
