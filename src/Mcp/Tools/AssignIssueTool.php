<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\AuditLogger;
use Vigilance\Models\FailureGroup;

#[Description('Assign an error issue to a specific owner (e.g. an email/handle) so it shows up in their queue — or pass an empty assignee to unassign. Unlike "acknowledge-issue" (which assigns to the MCP actor), this routes to an arbitrary owner. Requires MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true). Audited.')]
#[IsDestructive]
class AssignIssueTool extends Tool
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
                ->description('The issue id to (re)assign.')
                ->required(),
            'assignee' => $schema->string()
                ->description('Owner identifier to assign to (email/handle). Empty string unassigns.'),
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

        $assignee = trim((string) $request->get('assignee')) ?: null;

        FailureGroup::query()->whereKey($id)->update(['assignee' => $assignee]);

        $audit->log(
            action: 'assign_issue',
            subject: (string) $id,
            meta: ['name' => $issue->name, 'assignee' => $assignee, 'source' => 'mcp'],
            user: $this->actor($request),
        );

        return $this->json([
            'ok' => true,
            'id' => $id,
            'assignee' => $assignee,
            'status' => $assignee !== null ? 'assigned' : 'unassigned',
        ]);
    }
}
