<?php

namespace Vigilance\Http\Livewire;

use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Metrics\Stats;
use Vigilance\Metrics\Workload;
use Vigilance\Vigilance;

/**
 * One independent, lazily-loaded APM card. Each card on the dashboard is a
 * separate Livewire instance that loads only its own data — so a slow card never
 * blocks the rest of the page, and the dashboard blade can be published,
 * rearranged, resized (cols) or extended with custom cards.
 */
#[Lazy]
class ApmCard extends Component
{
    public string $card = 'servers';

    public string $cols = 'full';

    public string $period = '1h';

    public string $usageMode = 'requests';

    public function mount(string $card, string $cols = 'full', string $period = '1h'): void
    {
        $this->card = $card;
        $this->cols = $cols;
        $this->period = $period;
    }

    public function setUsageMode(string $mode): void
    {
        if (in_array($mode, ['requests', 'jobs'], true)) {
            $this->usageMode = $mode;
        }
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="animate-pulse rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="h-3 w-24 rounded bg-zinc-200 dark:bg-zinc-800"></div>
            <div class="mt-4 space-y-2">
                <div class="h-2 w-full rounded bg-zinc-100 dark:bg-zinc-800/70"></div>
                <div class="h-2 w-5/6 rounded bg-zinc-100 dark:bg-zinc-800/70"></div>
                <div class="h-2 w-2/3 rounded bg-zinc-100 dark:bg-zinc-800/70"></div>
            </div>
        </div>
        HTML;
    }

    public function render()
    {
        $storage = app(Storage::class);
        $interval = $this->interval();

        return view('vigilance::apm.cards.'.$this->cardView(), array_merge(
            ['card' => $this->card, 'period' => $this->period],
            $this->data($storage, $interval),
        ));
    }

    protected function cardView(): string
    {
        $allowed = [
            'servers', 'slow_requests', 'slow_queries', 'slow_outgoing',
            'exceptions', 'cache', 'usage', 'throughput', 'queues',
            'slow_jobs', 'sends_logs', 'uptime',
        ];

        return in_array($this->card, $allowed, true) ? $this->card : 'missing';
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(Storage $storage, CarbonInterval $interval): array
    {
        return match ($this->card) {
            'servers' => ['servers' => $this->servers($storage, $interval)],
            'slow_requests' => ['rows' => $storage->aggregate('slow_request', ['max', 'count'], $interval, 'max', 'desc', 25)],
            'slow_queries' => ['rows' => $storage->aggregate('slow_query', ['max', 'count'], $interval, 'max', 'desc', 25)],
            'slow_outgoing' => ['rows' => $storage->aggregate('slow_outgoing_request', ['max', 'count'], $interval, 'max', 'desc', 25)],
            'exceptions' => ['rows' => $storage->aggregate('exception', ['max', 'count'], $interval, 'count', 'desc', 25)],
            'cache' => $this->cache($storage, $interval),
            'usage' => ['usage' => $this->usage($storage, $interval), 'usageMode' => $this->usageMode],
            'throughput' => ['rows' => $this->jobThroughput($storage, $interval)],
            'queues' => ['queues' => app(Workload::class)->queues()],
            'slow_jobs' => [
                'rows' => $storage->aggregate('slow_job', ['max', 'count'], $interval, 'max', 'desc', 12),
                'recent' => app(Stats::class)->slowest(5),
            ],
            'sends_logs' => [
                'mail' => $storage->aggregate('mail', ['count'], $interval, 'count', 'desc', 8),
                'notifications' => $storage->aggregate('notification', ['count'], $interval, 'count', 'desc', 8),
                'logs' => $storage->aggregate('log', ['count'], $interval, 'count', 'desc', 8),
            ],
            'uptime' => ['endpoints' => $this->uptime($storage)],
            default => [],
        };
    }

    protected function interval(): CarbonInterval
    {
        return match ($this->period) {
            '6h' => CarbonInterval::hours(6),
            '24h' => CarbonInterval::hours(24),
            '7d' => CarbonInterval::days(7),
            default => CarbonInterval::hour(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function cache(Storage $storage, CarbonInterval $interval): array
    {
        return [
            'cacheHits' => (int) round($storage->aggregateTotal('cache_hit', 'count', $interval)),
            'cacheMisses' => (int) round($storage->aggregateTotal('cache_miss', 'count', $interval)),
            'cacheKeys' => $storage->aggregate('cache_miss', ['count'], $interval, 'count', 'desc', 8),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function servers(Storage $storage, CarbonInterval $interval): array
    {
        $systems = $storage->values('system');
        $cpuGraph = $storage->graph(['cpu'], 'avg', $interval);
        $memoryGraph = $storage->graph(['memory'], 'avg', $interval);

        $rows = [];
        $now = time();

        foreach ($systems as $slug => $row) {
            $info = json_decode((string) $row->value, true);
            $info = is_array($info) ? $info : [];
            $updatedAt = (int) ($info['updated_at'] ?? $row->timestamp ?? 0);

            $rows[] = [
                'slug' => (string) $slug,
                'name' => (string) ($info['name'] ?? $slug),
                'cpu' => (int) ($info['cpu'] ?? 0),
                'memory_used' => (int) ($info['memory_used'] ?? 0),
                'memory_total' => (int) ($info['memory_total'] ?? 0),
                'storage' => is_array($info['storage'] ?? null) ? $info['storage'] : [],
                'updated_at' => $updatedAt,
                'online' => $updatedAt > 0 && ($now - $updatedAt) <= 60,
                'cpu_series' => $this->series($cpuGraph, (string) $slug, 'cpu'),
                'memory_series' => $this->series($memoryGraph, (string) $slug, 'memory'),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function usage(Storage $storage, CarbonInterval $interval): array
    {
        $primaryType = $this->usageMode === 'jobs' ? 'user_job' : 'user_request';
        $primary = $storage->aggregate($primaryType, ['count'], $interval, 'count', 'desc', 10);

        if ($primary->isEmpty()) {
            return [];
        }

        $slow = $storage->aggregate('slow_user_request', ['count'], $interval, 'count', 'desc', 101)->keyBy('key');
        $users = $storage->values('user');

        $ids = $primary->map(fn ($row) => (string) $row->key)->all();
        $resolved = Vigilance::apmUsers($ids); // read-time resolution (name/avatar)

        $rows = [];
        foreach ($primary as $row) {
            $id = (string) $row->key;

            // Prefer the live resolver; fall back to the write-time snapshot.
            $info = $resolved[$id] ?? null;
            if ($info === null) {
                $display = $users->get($id);
                $decoded = $display ? json_decode((string) $display->value, true) : null;
                $info = is_array($decoded) ? $decoded : [];
            }

            $rows[] = [
                'id' => $id,
                'name' => (string) ($info['name'] ?? ('User #'.$id)),
                'extra' => (string) ($info['extra'] ?? ''),
                'avatar' => isset($info['avatar']) && is_string($info['avatar']) ? $info['avatar'] : null,
                'count' => (int) $row->count,
                'slow' => (int) ($slow->get($id)->count ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function jobThroughput(Storage $storage, CarbonInterval $interval): array
    {
        $processed = $this->sumGraph($storage->graph(['queue_processed'], 'count', $interval));
        $failed = $this->sumGraph($storage->graph(['queue_failed'], 'count', $interval));
        $released = $this->sumGraph($storage->graph(['queue_released'], 'count', $interval));

        $keys = array_unique([...array_keys($processed), ...array_keys($failed), ...array_keys($released)]);

        $rows = [];
        foreach ($keys as $key) {
            $rows[] = [
                'queue' => $key,
                'processed' => $processed[$key] ?? 0,
                'failed' => $failed[$key] ?? 0,
                'released' => $released[$key] ?? 0,
            ];
        }

        usort($rows, fn ($a, $b) => $b['processed'] <=> $a['processed']);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function uptime(Storage $storage): array
    {
        $rows = [];
        $now = time();

        foreach ($storage->values('uptime') as $row) {
            $info = json_decode((string) $row->value, true);
            $info = is_array($info) ? $info : [];
            $checkedAt = (int) ($info['checked_at'] ?? $row->timestamp ?? 0);

            $rows[] = [
                'url' => (string) ($info['url'] ?? $row->key),
                'up' => (bool) ($info['up'] ?? false),
                'status' => (int) ($info['status'] ?? 0),
                'latency_ms' => (int) ($info['latency_ms'] ?? 0),
                'checked_at' => $checkedAt,
                'fresh' => $checkedAt > 0 && ($now - $checkedAt) <= 300,
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<array-key, mixed>  $graph
     * @return list<int|null>
     */
    protected function series(Collection $graph, string $key, string $type): array
    {
        $node = $graph->get($key);
        if (! $node instanceof Collection) {
            return [];
        }
        $typeNode = $node->get($type);

        return $typeNode instanceof Collection ? array_values($typeNode->all()) : [];
    }

    /**
     * @param  Collection<array-key, mixed>  $graph
     * @return array<string, int>
     */
    protected function sumGraph(Collection $graph): array
    {
        $out = [];
        foreach ($graph as $key => $types) {
            $sum = 0;
            if ($types instanceof Collection) {
                foreach ($types as $series) {
                    if ($series instanceof Collection) {
                        $sum += (int) array_sum(array_filter($series->all(), fn ($v) => $v !== null));
                    }
                }
            }
            $out[(string) $key] = $sum;
        }

        return $out;
    }
}
