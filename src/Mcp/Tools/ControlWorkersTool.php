<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\AuditLogger;
use Vigilance\Supervision\ControlPlane;

#[Description('Control the whole Vigilance worker fleet — the global control plane behind vigilance:pause / continue / restart / terminate. Actions: "pause" (workers stop pulling new jobs), "resume", "restart" (rolling restart, e.g. after a deploy), "terminate" (gracefully stop the supervisor and all workers). Requires MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true). Audited. To pause a single queue instead of the whole fleet, use "pause-queue".')]
#[IsDestructive]
class ControlWorkersTool extends Tool
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
            'action' => $schema->string()
                ->description('What to do to the fleet.')
                ->enum(['pause', 'resume', 'restart', 'terminate'])
                ->required(),
        ];
    }

    public function handle(Request $request, ControlPlane $control, AuditLogger $audit): Response
    {
        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        $action = (string) $request->get('action');

        match ($action) {
            'pause' => $control->pause(),
            'resume' => $control->continue(),
            'restart' => $control->restart(),
            'terminate' => $control->terminate(),
            default => null,
        };

        if (! in_array($action, ['pause', 'resume', 'restart', 'terminate'], true)) {
            return Response::error('Unknown action. Use one of: pause, resume, restart, terminate.');
        }

        $audit->log(
            action: $action.'_workers',
            meta: ['source' => 'mcp'],
            user: $this->actor($request),
        );

        return $this->json(['ok' => true, 'action' => $action, 'control' => $control->status()]);
    }
}
