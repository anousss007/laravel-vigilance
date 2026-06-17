<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Models\MonitoredTag;
use Vigilance\Models\RunTag;

#[Description('Tags seen on recent runs (last 7 days) with their run counts and last-seen time, flagging which are pinned/monitored. Tags come from a job\'s tags() method and are a filter dimension for runs.')]
#[IsReadOnly]
class TagsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $limit = $this->resolveLimit();

        /** @var list<string> $monitored */
        $monitored = MonitoredTag::query()->pluck('tag')->all();

        $tags = RunTag::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('tag')
            ->selectRaw('count(*) as runs')
            ->selectRaw('max(created_at) as last_seen')
            ->groupBy('tag')
            ->orderByDesc('runs')
            ->limit($limit)
            ->get();

        return $this->json([
            'count' => $tags->count(),
            'tags' => $tags->map(fn (RunTag $t): array => [
                'tag' => (string) $t->tag,
                'runs' => (int) $t->getAttribute('runs'),
                'monitored' => in_array($t->tag, $monitored, true),
                'last_seen' => $t->getAttribute('last_seen')
                    ? Carbon::parse($t->getAttribute('last_seen'))->toIso8601String()
                    : null,
            ])->all(),
        ]);
    }
}
