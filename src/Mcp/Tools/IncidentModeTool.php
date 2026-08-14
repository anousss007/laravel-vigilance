<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\AuditLogger;
use Vigilance\Support\IncidentMode;

/**
 * The agentic version of the incident-mode button: "I am about to investigate
 * something, stop sampling away the evidence for the next N minutes."
 *
 * A write, because it changes what the whole application records. Safe to hand
 * to an agent precisely because it cannot be left on — the duration is capped
 * and the switch expires on its own.
 */
#[Description('Engage or end incident mode: keep every trace, stop sampling anything out and lower the log floor for N minutes, then it reverts automatically. Use it BEFORE reproducing a problem so the detail is captured, rather than raising sample rates in config. Reading the current state needs no writes; changing it requires vigilance.mcp.allow_writes and vigilance.incident_mode.enabled. Recorded in the audit log.')]
#[IsDestructive]
class IncidentModeTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('"status" (default, read-only), "engage" or "end".'),
            'minutes' => $schema->integer()
                ->description('How long to engage for. Capped by vigilance.incident_mode.max_minutes. Defaults to 30.'),
        ];
    }

    public function handle(Request $request, AuditLogger $audit): Response
    {
        $action = strtolower((string) ($request->get('action') ?: 'status'));

        if ($action === 'status') {
            return $this->json($this->state());
        }

        if (! config('vigilance.incident_mode.enabled', false)) {
            return Response::error('Incident mode is not available. Set vigilance.incident_mode.enabled to make the switch usable.');
        }

        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        if ($action === 'end') {
            IncidentMode::disengage();
            $audit->log(action: 'incident_mode_end', meta: ['source' => 'mcp'], user: $this->actor($request));

            return $this->json(['ok' => true] + $this->state());
        }

        if ($action !== 'engage') {
            return Response::error("Unknown action [{$action}]. Use status, engage or end.");
        }

        $result = IncidentMode::engage($request->integer('minutes') ?: 30, $this->actor($request));

        $audit->log(
            action: 'incident_mode_engage',
            meta: ['minutes' => $result['minutes'], 'source' => 'mcp'],
            user: $this->actor($request),
        );

        return $this->json([
            'ok' => true,
            'engaged_for_minutes' => $result['minutes'],
            'note' => 'This expires on its own — there is nothing to remember to turn off.',
        ] + $this->state());
    }

    /**
     * @return array<string, mixed>
     */
    protected function state(): array
    {
        $state = IncidentMode::state();

        return [
            'available' => (bool) config('vigilance.incident_mode.enabled', false),
            'active' => $state !== null,
            'seconds_remaining' => IncidentMode::secondsRemaining(),
            'engaged_by' => $state['by'] ?? null,
        ];
    }
}
