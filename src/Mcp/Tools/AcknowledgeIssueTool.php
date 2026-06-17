<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\AuditLogger;
use Vigilance\Models\FailureGroup;

#[Description('Acknowledge an error issue and assign it to the MCP actor (marks that someone is on it). Requires writes to be enabled (vigilance.mcp.allow_writes). Recorded in the audit log.')]
#[IsDestructive]
class AcknowledgeIssueTool extends Tool
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
            'id' => $schema->integer()
                ->description('The issue id to acknowledge.')
                ->required(),
        ];
    }

    public function handle(Request $request, AuditLogger $audit): Response
    {
        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        $id = $request->integer('id');
        $issue = FailureGroup::query()->find($id);

        if ($issue === null) {
            return Response::error("Issue [{$id}] not found.");
        }

        $actor = $this->actor($request);

        FailureGroup::query()->whereKey($id)->update([
            'acknowledged_at' => now(),
            'assignee' => $actor,
        ]);

        $audit->log(action: 'acknowledge_issue', subject: (string) $id, meta: ['name' => $issue->name, 'source' => 'mcp'], user: $actor);

        return $this->json(['ok' => true, 'id' => $id, 'status' => 'acknowledged', 'assignee' => $actor]);
    }
}
