<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Vigilance\Control\AuditLogger;
use Vigilance\Models\Suppression;
use Vigilance\Support\Suppressions;

/**
 * Listing is as important as creating here: "there is no data for this route"
 * has two very different causes, and an agent that cannot see the active rules
 * will happily conclude the app is idle when it is actually muted.
 */
#[Description('List, create or remove the telemetry rules created from the dashboard (muted routes, grouped cache keys, silenced exceptions). ALWAYS list these before concluding that a route or query has no data — a muted route looks exactly like an idle one. Listing is read-only; creating or removing requires vigilance.mcp.allow_writes and is recorded in the audit log.')]
#[IsDestructive]
class SuppressionsTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('"list" (default, read-only), "create" or "remove".'),
            'scope' => $schema->string()
                ->description('For create: route | query | cache_key | exception.'),
            'pattern' => $schema->string()
                ->description('For create: a wildcard ("/admin/*") or a delimited regex ("#^/internal/#").'),
            'group_as' => $schema->string()
                ->description('For create: collapse matches into this key instead of dropping them (bounds cardinality while keeping the signal).'),
            'expires_in_minutes' => $schema->integer()
                ->description('For create: auto-remove the rule after this long. Prefer setting it — a permanent mute is rarely what you meant.'),
            'id' => $schema->integer()
                ->description('For remove: the rule id (from "list").'),
        ];
    }

    public function handle(Request $request, AuditLogger $audit): Response
    {
        $action = strtolower((string) ($request->get('action') ?: 'list'));

        if ($action === 'list') {
            return $this->json([
                'rules' => Suppression::query()->active()->orderBy('scope')->orderBy('pattern')->get()
                    ->map(fn (Suppression $rule) => [
                        'id' => $rule->id,
                        'scope' => $rule->scope,
                        'pattern' => $rule->pattern,
                        'action' => $rule->action,
                        'group_as' => $rule->replacement,
                        'expires_at' => $rule->expires_at?->toIso8601String(),
                        'created_by' => $rule->created_by,
                    ])->all(),
            ]);
        }

        if (! $this->writesEnabled()) {
            return Response::error('Vigilance MCP writes are disabled. Set vigilance.mcp.allow_writes to enable them.');
        }

        return match ($action) {
            'create' => $this->create($request, $audit),
            'remove' => $this->remove($request, $audit),
            default => Response::error("Unknown action [{$action}]. Use list, create or remove."),
        };
    }

    protected function create(Request $request, AuditLogger $audit): Response
    {
        $scope = (string) $request->get('scope');
        $pattern = trim((string) $request->get('pattern'));
        $groupAs = $request->get('group_as');
        $minutes = $request->integer('expires_in_minutes');

        if (! in_array($scope, Suppression::scopes(), true)) {
            return Response::error('Unknown scope ['.$scope.']. Use one of: '.implode(', ', Suppression::scopes()).'.');
        }

        if ($pattern === '') {
            return Response::error('A pattern is required.');
        }

        $rule = Suppression::query()->updateOrCreate(
            ['scope' => $scope, 'pattern' => $pattern],
            [
                'action' => $groupAs ? 'group' : 'ignore',
                'replacement' => $groupAs ? (string) $groupAs : null,
                'created_by' => $this->actor($request),
                'expires_at' => $minutes > 0 ? now()->addMinutes($minutes) : null,
            ],
        );

        Suppressions::forget();

        $audit->log(
            action: 'create_suppression',
            subject: $scope.':'.$pattern,
            meta: ['group_as' => $groupAs, 'expires_in_minutes' => $minutes ?: null, 'source' => 'mcp'],
            user: $this->actor($request),
        );

        return $this->json([
            'ok' => true,
            'id' => $rule->id,
            'effect' => $groupAs
                ? "matches are now recorded as [{$groupAs}]"
                : 'matching telemetry is no longer recorded',
            'expires_at' => $rule->expires_at?->toIso8601String(),
        ]);
    }

    protected function remove(Request $request, AuditLogger $audit): Response
    {
        $id = $request->integer('id');
        $rule = Suppression::query()->find($id);

        if ($rule === null) {
            return Response::error("Rule [{$id}] not found.");
        }

        $rule->delete();
        Suppressions::forget();

        $audit->log(
            action: 'remove_suppression',
            subject: $rule->scope.':'.$rule->pattern,
            meta: ['source' => 'mcp'],
            user: $this->actor($request),
        );

        return $this->json(['ok' => true, 'id' => $id, 'status' => 'removed']);
    }
}
