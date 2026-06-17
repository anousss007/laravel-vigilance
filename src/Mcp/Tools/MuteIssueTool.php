<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\AuditLogger;
use Vigilance\Models\FailureGroup;

#[Description('Mute an error issue for a number of hours (suppresses its alerting until then). Requires writes to be enabled (vigilance.mcp.allow_writes). Recorded in the audit log.')]
#[IsDestructive]
class MuteIssueTool extends Tool
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
                ->description('The issue id to mute.')
                ->required(),
            'hours' => $schema->integer()
                ->description('How many hours to mute it for. Defaults to 24.'),
        ];
    }

    public function handle(Request $request, AuditLogger $audit): Response
    {
        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        $id = $request->integer('id');
        $hours = max(1, $request->integer('hours') ?: 24);
        $issue = FailureGroup::query()->find($id);

        if ($issue === null) {
            return Response::error("Issue [{$id}] not found.");
        }

        $until = now()->addHours($hours);

        FailureGroup::query()->whereKey($id)->update(['muted_until' => $until]);

        $audit->log(action: 'mute_issue', subject: (string) $id, meta: ['hours' => $hours, 'source' => 'mcp'], user: $this->actor($request));

        return $this->json(['ok' => true, 'id' => $id, 'status' => 'muted', 'muted_until' => $until->toIso8601String()]);
    }
}
