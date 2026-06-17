<?php

namespace Vigilance\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\Workload;

#[Description('Per-queue workload over the recent window: live backlog depth, active worker count, last-hour throughput / failures / average runtime / average wait, and an estimated time-to-clear the backlog.')]
#[IsReadOnly]
class QueuesTool extends Tool
{
    public function handle(Request $request, Workload $workload): Response
    {
        $queues = array_map(function (array $queue): array {
            // Drop the per-queue sparkline series — the agent wants the numbers,
            // not 60 points of history.
            unset($queue['series']);

            return $queue;
        }, $workload->queues());

        return $this->json([
            'count' => count($queues),
            'queues' => $queues,
        ]);
    }
}
