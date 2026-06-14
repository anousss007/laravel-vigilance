@php
    $fmtMs = function (?int $ms): string {
        if ($ms === null) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms.'ms';
        }
        return number_format($ms / 1000, 2).'s';
    };
    $fmtMem = fn (?int $b) => $b === null ? '—' : number_format($b / 1048576, 1).'MB';
@endphp

<div wire:poll.visible.5s class="space-y-4">
    <div class="flex items-baseline justify-between">
        <h1 class="text-base font-semibold">Workload</h1>
        <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ count($queues) }} queues</span>
    </div>

    <div class="flex flex-wrap items-center gap-4 rounded-lg border border-zinc-200 bg-white px-4 py-3 text-xs dark:border-zinc-800 dark:bg-zinc-900">
        <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">System load</span>
        @if ($load !== null)
            <span><span class="font-semibold">{{ $load[1] }}</span> <span class="text-zinc-600 dark:text-zinc-400">1m</span></span>
            <span><span class="font-semibold">{{ $load[5] }}</span> <span class="text-zinc-600 dark:text-zinc-400">5m</span></span>
            <span><span class="font-semibold">{{ $load[15] }}</span> <span class="text-zinc-600 dark:text-zinc-400">15m</span></span>
        @else
            <span class="text-zinc-600 dark:text-zinc-400">n/a on this platform (sys_getloadavg unavailable)</span>
        @endif
    </div>

    <p class="text-xs text-zinc-600 dark:text-zinc-400">Live queue depth is only available for the <code>database</code> and <code>redis</code> drivers; other drivers show “n/a”.</p>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($queues as $queue)
            @php
                $series = collect($queue['series'])->map(fn ($p) => (int) ($p->throughput ?? 0))->values();
                $maxPt = max(1, $series->max() ?? 1);
                $sw = 240; $sh = 40; $n = max(1, $series->count());
                $stp = $n > 1 ? $sw / ($n - 1) : $sw;
                $path = '';
                foreach ($series as $i => $v) {
                    $x = round($i * $stp, 2);
                    $y = round($sh - (($v / $maxPt) * ($sh - 4)) - 2, 2);
                    $path .= ($i === 0 ? 'M' : 'L').$x.' '.$y.' ';
                }
            @endphp
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-baseline justify-between">
                    <h2 class="truncate font-semibold">{{ $queue['queue'] }}</h2>
                    <span class="text-[10px] text-zinc-600 dark:text-zinc-400">{{ $queue['connection_name'] ?: 'no connection' }}</span>
                </div>

                <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Depth</div>
                        <div class="font-semibold">{{ $queue['depth'] === null ? 'n/a' : $queue['depth'] }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Workers</div>
                        <div class="font-semibold">{{ $queue['workers'] }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Clear in</div>
                        <div class="font-semibold">{{ $fmtMs($queue['time_to_clear_ms']) }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Proc/1h</div>
                        <div class="font-semibold">{{ $queue['processed_last_hour'] }}<span class="text-[10px] text-red-700 dark:text-red-300">{{ $queue['failed_last_hour'] > 0 ? ' · '.$queue['failed_last_hour'].'✗' : '' }}</span></div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Avg run</div>
                        <div class="font-semibold">{{ $fmtMs($queue['avg_runtime_ms']) }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Avg wait</div>
                        <div class="font-semibold">{{ $fmtMs($queue['avg_wait_ms']) }}</div>
                    </div>
                </div>

                <svg viewBox="0 0 {{ $sw }} {{ $sh }}" preserveAspectRatio="none" class="mt-3 h-10 w-full">
                    @if ($path)
                        <path d="{{ $path }}" fill="none" stroke="rgb(59 130 246)" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                    @else
                        <line x1="0" y1="{{ $sh - 2 }}" x2="{{ $sw }}" y2="{{ $sh - 2 }}" stroke="rgb(113 113 122 / 0.4)" stroke-dasharray="3 3" />
                    @endif
                </svg>
            </div>
        @empty
            <div class="md:col-span-2 xl:col-span-3 rounded-lg border border-zinc-200 bg-white p-10 text-center text-xs text-zinc-600 dark:text-zinc-400 dark:border-zinc-800 dark:bg-zinc-900">
                No queue activity in the last 24 hours.
            </div>
        @endforelse
    </div>

    @if (count($jobClasses))
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-baseline justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="font-semibold">By job class</h2>
                <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">last 24 hours</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2 font-medium">Job</th>
                            <th class="px-4 py-2 text-right font-medium">Runs</th>
                            <th class="px-4 py-2 text-right font-medium">Failed</th>
                            <th class="px-4 py-2 text-right font-medium">Fail %</th>
                            <th class="px-4 py-2 text-right font-medium">Avg</th>
                            <th class="px-4 py-2 text-right font-medium">Max</th>
                            <th class="px-4 py-2 text-right font-medium">Avg mem</th>
                            <th class="px-4 py-2 text-right font-medium">Avg CPU</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($jobClasses as $jc)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="px-4 py-2 font-medium" title="{{ $jc['name'] }}">{{ class_basename($jc['name']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $jc['runs'] }}</td>
                                <td class="px-4 py-2 text-right {{ $jc['failed'] > 0 ? 'text-red-700 dark:text-red-300' : '' }}">{{ $jc['failed'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $jc['fail_rate'] }}%</td>
                                <td class="px-4 py-2 text-right">{{ $fmtMs($jc['avg_ms']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $fmtMs($jc['max_ms']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $fmtMem($jc['avg_memory']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $jc['avg_cpu'] === null ? '—' : $fmtMs($jc['avg_cpu']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
