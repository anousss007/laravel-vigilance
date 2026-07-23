<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\Exceptions\NotAllowed;
use Vigilance\Control\QueueManager;

#[Description('Cancel (delete) specific pending jobs by their backend id — database driver only. Destructive and irreversible. Requires BOTH manual control (VIGILANCE_CONTROL_ENABLED=true) AND MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true). Audited. Discover ids with "pending"; to purge a whole queue use "clear-queue".')]
#[IsDestructive]
class CancelPendingTool extends Tool
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
                ->description('The queue connection (must use the database driver).')
                ->required(),
            'ids' => $schema->array()
                ->items($schema->integer())
                ->description('The pending job ids to cancel (from the "pending" tool).')
                ->required(),
        ];
    }

    public function handle(Request $request, QueueManager $queues): Response
    {
        if (! $this->controlEnabled() || ! $this->writesEnabled()) {
            return Response::error('Cancelling pending jobs requires both vigilance.control.enabled and vigilance.mcp.allow_writes to be true.');
        }

        $connection = trim((string) $request->get('connection'));

        if ($connection === '') {
            return Response::error('The "connection" parameter is required.');
        }

        $ids = array_values(array_filter(
            array_map('intval', (array) $request->get('ids', [])),
            fn (int $id) => $id > 0,
        ));

        if ($ids === []) {
            return Response::error('Provide at least one valid job id in "ids".');
        }

        try {
            $deleted = $queues->deletePending($connection, $ids, $this->actor($request));
        } catch (NotAllowed $e) {
            return Response::error($e->getMessage());
        }

        return $this->json(['ok' => true, 'connection' => $connection, 'requested' => count($ids), 'deleted' => $deleted]);
    }
}
