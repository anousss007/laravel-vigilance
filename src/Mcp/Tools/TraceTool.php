<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Logs\Contracts\LogStorage;
use Vigilance\Logs\LogEntry;
use Vigilance\Tracing\Contracts\TraceStorage;
use Vigilance\Tracing\Span;

#[Description('Get one trace in full: its span waterfall (each query / cache / outgoing-HTTP / child op with offset and duration) plus the application logs correlated to it, in timeline order. Pass the trace id from the "traces" tool.')]
#[IsReadOnly]
class TraceTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The trace id.')
                ->required(),
        ];
    }

    public function handle(Request $request, TraceStorage $traces, LogStorage $logs): Response
    {
        $id = (string) $request->get('id');
        $trace = $traces->find($id);

        if ($trace === null) {
            return Response::error("Trace [{$id}] not found (it may have been trimmed, or tracing is disabled).");
        }

        $spans = array_map(fn (Span $s): array => [
            'type' => $s->type,
            'label' => $this->truncate($s->label),
            'offset_ms' => $s->offsetMs(),
            'duration_ms' => $s->durationMs(),
            'attributes' => $this->redact($s->attributes),
        ], $trace->spans);

        $logRows = $logs->forTrace($trace->id, $this->resolveLimit(null))
            ->map(fn (LogEntry $l): array => [
                'level' => $l->level,
                'message' => $this->truncate($l->message),
                'channel' => $l->channel,
                'logged_at' => date('c', $l->loggedAt),
                'context' => $this->redact($l->context),
            ])->all();

        return $this->json([
            'id' => $trace->id,
            'type' => $trace->type,
            'name' => $trace->name,
            'status' => $trace->status,
            'duration_ms' => $trace->durationMs,
            'span_count' => $trace->spanCount,
            'dropped_spans' => $trace->droppedSpans,
            'user_id' => $trace->userId,
            'started_at' => date('c', $trace->startedAt),
            'attributes' => $this->redact($trace->attributes),
            'spans' => $spans,
            'logs' => $logRows,
        ]);
    }
}
