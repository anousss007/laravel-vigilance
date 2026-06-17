<?php

namespace Vigilance\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\Slo;
use Vigilance\Metrics\SloStatus;

#[Description('Current service-level objectives: each SLO\'s SLI attainment vs its target, how much error budget remains, and the short-window burn rate (>1 means the budget is burning faster than the target sustains). Configure SLOs under vigilance.slos.')]
#[IsReadOnly]
class SlosTool extends Tool
{
    public function handle(Request $request, Slo $slo): Response
    {
        $rows = $slo->all()
            ->map(fn (SloStatus $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'sli' => $s->sli,
                'status' => $s->status(),
                'target' => $s->target,
                'current' => $s->current,
                'window_days' => $s->windowDays,
                'budget_remaining' => $s->budgetRemaining,
                'burn_rate' => $s->burnRate,
                'events' => $s->events,
            ])->all();

        return $this->json([
            'count' => count($rows),
            'slos' => $rows,
        ]);
    }
}
