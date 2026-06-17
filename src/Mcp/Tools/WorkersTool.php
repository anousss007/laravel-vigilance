<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Models\SupervisorRecord;
use Vigilance\Models\WorkerRecord;
use Vigilance\Supervision\SupervisorState;

#[Description('The worker fleet (the Horizon-replacement supervisor view): every live supervisor across all nodes — connection, queues, process count, balance, status, heartbeat — and the worker processes it owns. Empty if you run queue:work/Horizon instead of vigilance:supervise.')]
#[IsReadOnly]
class WorkersTool extends Tool
{
    public function handle(Request $request, SupervisorState $state): Response
    {
        $expire = (int) config('vigilance.supervision.heartbeat_expire', 30);

        $supervisors = $state->active($expire);

        $workers = WorkerRecord::query()
            ->orderBy('supervisor')
            ->orderBy('pid')
            ->get(['supervisor', 'host', 'pid', 'queue', 'connection', 'status', 'last_heartbeat_at'])
            ->groupBy(fn (WorkerRecord $w): string => $w->supervisor.'@'.$w->host);

        return $this->json([
            'count' => $supervisors->count(),
            'supervisors' => $supervisors->map(function (SupervisorRecord $s) use ($workers): array {
                /** @var Collection<int, WorkerRecord> $own */
                $own = $workers[$s->name.'@'.$s->host] ?? new Collection;

                return [
                    'name' => $s->name,
                    'host' => $s->host,
                    'pid' => $s->pid,
                    'status' => $s->status,
                    'connection' => $s->connection,
                    'queues' => $s->queues,
                    'balance' => $s->balance,
                    'processes' => $s->processes,
                    'pools' => $s->pools,
                    'last_heartbeat_at' => $this->date($s->last_heartbeat_at),
                    'workers' => $own->map(fn (WorkerRecord $w): array => [
                        'pid' => $w->pid,
                        'queue' => $w->queue,
                        'connection' => $w->connection,
                        'status' => $w->status,
                        'last_heartbeat_at' => $this->date($w->last_heartbeat_at),
                    ])->values()->all(),
                ];
            })->all(),
        ]);
    }
}
