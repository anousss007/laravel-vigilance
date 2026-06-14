@php $ago = fn (?int $ts) => $ts ? \Carbon\CarbonImmutable::createFromTimestamp($ts)->diffForHumans() : '—'; @endphp
<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800"><h2 class="text-sm font-semibold">Uptime</h2></div>
    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
        @forelse ($endpoints as $endpoint)
            <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                <div class="flex min-w-0 items-center gap-2">
                    <span @class([
                        'inline-block h-2 w-2 shrink-0 rounded-full',
                        'bg-emerald-500' => $endpoint['up'] && $endpoint['fresh'],
                        'bg-red-500' => ! $endpoint['up'] && $endpoint['fresh'],
                        'bg-zinc-400 dark:bg-zinc-600' => ! $endpoint['fresh'],
                    ]) aria-hidden="true"></span>
                    <span class="truncate font-mono text-xs">{{ $endpoint['url'] }}</span>
                </div>
                <div class="flex shrink-0 items-center gap-2 text-[11px]">
                    <span @class([
                        'rounded px-1.5 py-0.5',
                        'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $endpoint['up'],
                        'bg-red-500/10 text-red-700 dark:text-red-300' => ! $endpoint['up'],
                    ])>{{ $endpoint['up'] ? 'up' : 'down' }}{{ $endpoint['status'] ? ' '.$endpoint['status'] : '' }}</span>
                    <span class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ $endpoint['latency_ms'] }}ms</span>
                    <span class="text-zinc-500 dark:text-zinc-400">{{ $ago($endpoint['checked_at']) }}</span>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-xs text-zinc-600 dark:text-zinc-400">
                No uptime checks yet. Configure <code class="rounded bg-zinc-100 px-1 py-0.5 dark:bg-zinc-800">vigilance.uptime.urls</code> and schedule <code class="rounded bg-zinc-100 px-1 py-0.5 dark:bg-zinc-800">vigilance:health</code>.
            </li>
        @endforelse
    </ul>
</div>
