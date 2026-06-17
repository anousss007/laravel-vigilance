<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Models\FailureGroup;
use Vigilance\Support\Like;

#[Description('List grouped error issues (fingerprinted exceptions) with their status, source, occurrence count and first/last-seen times. Filter by status and source, or search the message and exception class. Use the "issue" tool for one issue\'s stacktrace and triggering runs.')]
#[IsReadOnly]
class IssuesTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['open', 'resolved', 'muted', 'acknowledged', 'all'])
                ->description('Which issues to return. Defaults to "open".'),
            'source' => $schema->string()
                ->description('Filter by origin: web, queue, command, reported, browser, ….'),
            'q' => $schema->string()
                ->description('Search the exception message and class (contains, case-insensitive).'),
            'limit' => $schema->integer()
                ->description('Max issues to return (capped by the server).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $status = (string) ($request->get('status') ?: 'open');
        $source = trim((string) $request->get('source'));
        $q = trim((string) $request->get('q'));
        $limit = $this->resolveLimit($request->integer('limit'));

        $issues = FailureGroup::query()
            ->when($status === 'open', fn ($query) => $query->whereNull('resolved_at'))
            ->when($status === 'resolved', fn ($query) => $query->whereNotNull('resolved_at'))
            ->when($status === 'muted', fn ($query) => $query->whereNull('resolved_at')->where('muted_until', '>', now()))
            ->when($status === 'acknowledged', fn ($query) => $query->whereNull('resolved_at')->whereNotNull('acknowledged_at'))
            ->when($source !== '', fn ($query) => $query->where('source', $source))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q): void {
                $term = Like::contains($q);
                $inner->whereRaw('message like ? escape ?', [$term, Like::ESCAPE])
                    ->orWhereRaw('exception_class like ? escape ?', [$term, Like::ESCAPE]);
            }))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->json([
            'status' => $status,
            'count' => $issues->count(),
            'issues' => $issues->map(fn (FailureGroup $g): array => [
                'id' => $g->id,
                'status' => $g->status(),
                'type' => $g->type,
                'source' => $g->source,
                'exception_class' => $g->exception_class,
                'message' => $this->truncate($g->message),
                'occurrences' => $g->occurrences,
                'priority' => $g->priority,
                'assignee' => $g->assignee,
                'first_release' => $g->first_release,
                'regressed' => $g->isRegressed(),
                'first_seen_at' => $this->date($g->first_seen_at),
                'last_seen_at' => $this->date($g->last_seen_at),
            ])->all(),
        ]);
    }
}
