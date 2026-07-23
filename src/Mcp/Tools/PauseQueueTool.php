<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\QueueManager;

#[Description('Pause a single queue: the supervisor stops pulling from it while every other queue keeps draining. Optionally auto-resume after "seconds"; otherwise it stays paused (surviving worker restarts/deploys) until resumed with "resume-queue". Requires MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true). Audited. Use "queues" to see connections/queue names and their current paused state.')]
#[IsDestructive]
class PauseQueueTool extends Tool
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
                ->description('The queue name to pause (e.g. "emails").')
                ->required(),
            'connection' => $schema->string()
                ->description('The queue connection the queue lives on. Defaults to vigilance.defaults.connection.'),
            'seconds' => $schema->integer()
                ->description('Auto-resume after this many seconds. Omit for an indefinite pause.'),
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

        $seconds = $request->get('seconds') !== null ? max(1, $request->integer('seconds')) : null;

        $queues->pause($connection, $queue, $seconds, $this->actor($request));

        return $this->json([
            'ok' => true,
            'connection' => $connection,
            'queue' => $queue,
            'paused_for_seconds' => $seconds,
        ]);
    }
}
