<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Logs\Contracts\LogStorage;
use Vigilance\Logs\LogEntry;
use Vigilance\Logs\LogLevel;

#[Description('Search captured application logs, optionally filtered by minimum level, channel, trace id, or a search term — each line correlated to the trace that emitted it. Requires log capture enabled (VIGILANCE_LOGS=true).')]
#[IsReadOnly]
class LogsTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()
                ->description('Search the log message (contains, case-insensitive).'),
            'level' => $schema->string()
                ->enum(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])
                ->description('Minimum severity to include.'),
            'channel' => $schema->string()
                ->description('Filter by log channel.'),
            'trace_id' => $schema->string()
                ->description('Only logs correlated to this trace id.'),
            'limit' => $schema->integer()
                ->description('Max log lines to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, LogStorage $logs): Response
    {
        $limit = $this->resolveLimit($request->integer('limit'));
        $q = trim((string) $request->get('q'));
        $level = strtolower(trim((string) $request->get('level')));
        $channel = trim((string) $request->get('channel'));
        $traceId = trim((string) $request->get('trace_id'));

        $filters = [];

        if ($q !== '') {
            $filters['q'] = $q;
        }

        if ($level !== '' && isset(LogLevel::VALUES[$level])) {
            $filters['min_level'] = LogLevel::value($level);
        }

        if ($channel !== '') {
            $filters['channel'] = $channel;
        }

        if ($traceId !== '') {
            $filters['trace_id'] = $traceId;
        }

        $rows = $logs->search($filters, $limit)
            ->map(fn (LogEntry $l): array => [
                'id' => $l->id,
                'level' => $l->level,
                'message' => $this->truncate($l->message),
                'channel' => $l->channel,
                'trace_id' => $l->traceId,
                'logged_at' => date('c', $l->loggedAt),
                'context' => $this->redact($l->context),
            ])->all();

        return $this->json([
            'count' => count($rows),
            'channels' => $logs->channels(),
            'logs' => $rows,
        ]);
    }
}
