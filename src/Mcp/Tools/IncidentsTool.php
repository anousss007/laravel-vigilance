<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Models\Incident;

#[Description('Fired alerts persisted as incidents (open while the condition recurs, auto-resolved when it stops), with level, occurrence count and time-to-resolution. Filter by open or resolved.')]
#[IsReadOnly]
class IncidentsTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['open', 'resolved', 'all'])
                ->description('Which incidents to return. Defaults to "open".'),
            'limit' => $schema->integer()
                ->description('Max incidents to return (capped by the server).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $status = (string) ($request->get('status') ?: 'open');
        $limit = $this->resolveLimit($request->integer('limit'));

        $incidents = Incident::query()
            ->when($status === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($status === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->json([
            'status' => $status,
            'count' => $incidents->count(),
            'incidents' => $incidents->map(fn (Incident $i): array => [
                'id' => $i->id,
                'key' => $i->key,
                'title' => $i->title,
                'message' => $this->truncate($i->message),
                'level' => $i->level,
                'status' => $i->isResolved() ? 'resolved' : 'open',
                'occurrences' => $i->occurrences,
                'duration_seconds' => $i->durationSeconds(),
                'opened_at' => $this->date($i->opened_at),
                'last_seen_at' => $this->date($i->last_seen_at),
                'resolved_at' => $this->date($i->resolved_at),
            ])->all(),
        ]);
    }
}
