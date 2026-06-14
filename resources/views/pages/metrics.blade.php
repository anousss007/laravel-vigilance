@php
    $fmtMs = fn (?int $ms) => $ms === null ? '—' : ($ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s');
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h1 class="text-base font-semibold">Metrics</h1>
        <span class="text-xs text-zinc-600 dark:text-zinc-400">per job class &amp; queue · last 24h</span>
    </div>

    {{-- Jobs --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
            <h2 class="text-sm font-semibold">By job class</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <caption class="sr-only">Job classes by throughput, failure rate and runtime</caption>
                <thead class="text-zinc-500 dark:text-zinc-400">
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th scope="col" class="px-4 py-2 font-medium">Job</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Runs</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Fail %</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Avg</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Max</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($jobs as $job)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-2">
                                <a href="{{ route('vigilance.metrics.show', ['type' => 'job', 'scope' => $job['name']]) }}" class="font-mono font-medium hover:underline">{{ $job['name'] }}</a>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($job['runs']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums {{ $job['fail_rate'] > 0 ? 'text-red-700 dark:text-red-300' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $job['fail_rate'] }}%</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $fmtMs($job['avg_ms']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-zinc-600 dark:text-zinc-400">{{ $fmtMs($job['max_ms']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-zinc-600 dark:text-zinc-400">No job metrics yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Queues --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
            <h2 class="text-sm font-semibold">By queue</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <caption class="sr-only">Queues by throughput and runtime</caption>
                <thead class="text-zinc-500 dark:text-zinc-400">
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th scope="col" class="px-4 py-2 font-medium">Queue</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Processed/h</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Avg runtime</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Avg wait</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($queues as $queue)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-2">
                                <a href="{{ route('vigilance.metrics.show', ['type' => 'queue', 'scope' => $queue['queue']]) }}" class="font-mono font-medium hover:underline">{{ $queue['queue'] }}</a>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($queue['processed_last_hour']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $fmtMs($queue['avg_runtime_ms']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-zinc-600 dark:text-zinc-400">{{ $fmtMs($queue['avg_wait_ms']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-zinc-600 dark:text-zinc-400">No active queues.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
