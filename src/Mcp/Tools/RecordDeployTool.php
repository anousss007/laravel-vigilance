<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\AuditLogger;
use Vigilance\Models\Deployment;

#[Description('Record a deployment marker so metric/error changes can be correlated with releases (the same marker vigilance:deploy writes, and what release-health / deploy-regression alerts compare against). Requires MCP writes (VIGILANCE_MCP_ALLOW_WRITES=true). Audited.')]
#[IsDestructive]
class RecordDeployTool extends Tool
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
            'release' => $schema->string()
                ->description('The release version/tag (e.g. "v1.4.0").'),
            'commit' => $schema->string()
                ->description('The deployed commit SHA.'),
            'notes' => $schema->string()
                ->description('Free-form notes about the deploy.'),
        ];
    }

    public function handle(Request $request, AuditLogger $audit): Response
    {
        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        $release = trim((string) $request->get('release')) ?: null;
        $commit = trim((string) $request->get('commit')) ?: null;
        $notes = trim((string) $request->get('notes')) ?: null;

        if ($release === null && $commit === null) {
            return Response::error('Provide at least a "release" or a "commit".');
        }

        $now = Carbon::now();
        $deployment = Deployment::query()->create([
            'version' => $release,
            'commit' => $commit,
            'environment' => app()->environment(),
            'notes' => $notes,
            'deployed_at' => $now,
            'created_at' => $now,
        ]);

        $audit->log(
            action: 'record_deploy',
            subject: $deployment->label(),
            meta: ['release' => $release, 'commit' => $commit, 'environment' => app()->environment(), 'source' => 'mcp'],
            user: $this->actor($request),
        );

        return $this->json([
            'ok' => true,
            'id' => $deployment->id,
            'label' => $deployment->label(),
            'environment' => app()->environment(),
        ]);
    }
}
