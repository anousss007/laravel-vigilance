<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Models\UserFeedback;

#[Description('Recent user-reported feedback captured from the widget: message, optional name/email, the page URL and the trace id the user was on (pivot to "trace" for the telemetry behind a complaint). Most recent first.')]
#[IsReadOnly]
class FeedbackTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max feedback entries to return (capped by the server).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = $this->resolveLimit($request->integer('limit') ?: null);

        $rows = UserFeedback::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'message', 'name', 'email', 'url', 'trace_id', 'user', 'created_at']);

        return $this->json([
            'count' => $rows->count(),
            'feedback' => $rows->map(fn (UserFeedback $f): array => [
                'id' => $f->id,
                'message' => $this->truncate($f->message),
                'name' => $f->name,
                'email' => $f->email,
                'url' => $f->url,
                'trace_id' => $f->trace_id,
                'user' => $f->user,
                'at' => $this->date($f->created_at),
            ])->all(),
        ]);
    }
}
