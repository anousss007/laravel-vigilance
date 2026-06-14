<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Models\MonitoredTag;
use Vigilance\Models\RunTag;

/**
 * Browse the tags seen across recent runs, pin the ones worth watching, and
 * drill into the runs for any tag.
 */
class Tags extends Component
{
    public function monitor(string $tag): void
    {
        MonitoredTag::query()->firstOrCreate(['tag' => $tag], ['created_at' => now()]);
    }

    public function unmonitor(string $tag): void
    {
        MonitoredTag::query()->whereKey($tag)->delete();
    }

    public function render()
    {
        $monitored = MonitoredTag::query()->orderBy('tag')->pluck('tag');

        $tags = RunTag::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('tag')
            ->selectRaw('count(*) as runs')
            ->selectRaw('max(created_at) as last_seen')
            ->groupBy('tag')
            ->orderByDesc('runs')
            ->limit(60)
            ->get();

        return view('vigilance::pages.tags', [
            'tags' => $tags,
            'monitored' => $monitored,
        ])->layout('vigilance::layout', ['title' => 'Tags']);
    }
}
