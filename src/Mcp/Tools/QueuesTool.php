<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\Workload;
use Vigilance\Supervision\ControlPlane;

#[Description('Per-queue workload over the recent window: live backlog depth, active worker count, last-hour throughput / failures / average runtime / average wait, an estimated time-to-clear the backlog, and whether the queue is currently paused. Also lists every paused queue with its auto-resume expiry.')]
#[IsReadOnly]
class QueuesTool extends Tool
{
    public function handle(Request $request, Workload $workload, ControlPlane $control): Response
    {
        $paused = [];
        foreach ($control->pausedQueues() as $entry) {
            $paused[$entry['connection'].'|'.$entry['queue']] = $entry['expires_at'];
        }

        $queues = array_map(function (array $queue) use ($paused): array {
            // Drop the per-queue sparkline series — the agent wants the numbers,
            // not 60 points of history.
            unset($queue['series']);

            $queue['paused'] = array_key_exists(($queue['connection_name'] ?? '').'|'.$queue['queue'], $paused);

            return $queue;
        }, $workload->queues());

        return $this->json([
            'count' => count($queues),
            'queues' => $queues,
            'paused_queues' => array_map(fn (array $e): array => [
                'connection' => $e['connection'],
                'queue' => $e['queue'],
                'expires_at' => $e['expires_at'] !== null ? $this->date(Carbon::createFromTimestamp($e['expires_at'])) : null,
            ], $control->pausedQueues()),
        ]);
    }
}
