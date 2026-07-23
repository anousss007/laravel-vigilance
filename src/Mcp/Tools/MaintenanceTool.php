<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\AuditLogger;
use Vigilance\Notifications\MaintenanceWindow;

#[Description('Suppress alert notifications during planned maintenance (e.g. before a deploy). Actions: "start" (open an ad-hoc window for "minutes"), "stop" (close it), "status" (is alerting suppressed?). While active, no rule pages; the next cycle after it closes re-evaluates and notifies anything still breaching. Requires MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true). Audited.')]
#[IsDestructive]
class MaintenanceTool extends Tool
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
                ->description('start | stop | status')
                ->enum(['start', 'stop', 'status'])
                ->required(),
            'minutes' => $schema->integer()
                ->description('For "start": window length in minutes (default 30).'),
        ];
    }

    public function handle(Request $request, MaintenanceWindow $maintenance, AuditLogger $audit): Response
    {
        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        $action = (string) $request->get('action');

        if ($action === 'status') {
            $until = $maintenance->adHocUntil();

            return $this->json([
                'ok' => true,
                'suppressed' => $maintenance->active(),
                'ad_hoc_until' => $until !== null ? $this->date(Carbon::createFromTimestamp($until)) : null,
            ]);
        }

        if ($action === 'stop') {
            $maintenance->stop();
            $audit->log(action: 'maintenance_stop', meta: ['source' => 'mcp'], user: $this->actor($request));

            return $this->json(['ok' => true, 'action' => 'stop', 'suppressed' => false]);
        }

        $minutes = max(1, $request->integer('minutes') ?: 30);
        $until = $maintenance->start($minutes);

        $audit->log(action: 'maintenance_start', meta: ['minutes' => $minutes, 'source' => 'mcp'], user: $this->actor($request));

        return $this->json([
            'ok' => true,
            'action' => 'start',
            'minutes' => $minutes,
            'suppressed' => true,
            'until' => $this->date(Carbon::createFromTimestamp($until)),
        ]);
    }
}
