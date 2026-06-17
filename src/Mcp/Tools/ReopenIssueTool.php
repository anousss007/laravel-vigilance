<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\AuditLogger;
use Vigilance\Models\FailureGroup;

#[Description('Reopen a previously resolved error issue (clears its resolved state). Requires writes to be enabled (vigilance.mcp.allow_writes). Recorded in the audit log.')]
#[IsDestructive]
class ReopenIssueTool extends Tool
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
                ->description('The issue id to reopen.')
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

        FailureGroup::query()->whereKey($id)->update(['resolved_at' => null]);

        $audit->log(action: 'reopen_issue', subject: (string) $id, meta: ['name' => $issue->name, 'source' => 'mcp'], user: $this->actor($request));

        return $this->json(['ok' => true, 'id' => $id, 'status' => 'open']);
    }
}
