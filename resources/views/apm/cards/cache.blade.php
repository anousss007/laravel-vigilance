@php
    $total = $cacheHits + $cacheMisses;
    $rate = $total > 0 ? round($cacheHits / $total * 100, 1) : null;
@endphp
<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800"><h2 class="text-sm font-semibold">Cache</h2></div>
    <div class="p-4">
        <div class="grid grid-cols-3 gap-3 text-center">
            <div><div class="text-xs text-zinc-600 dark:text-zinc-400">Hits</div><div class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-300">{{ number_format($cacheHits) }}</div></div>
            <div><div class="text-xs text-zinc-600 dark:text-zinc-400">Misses</div><div class="mt-1 text-xl font-semibold text-red-700 dark:text-red-300">{{ number_format($cacheMisses) }}</div></div>
            <div><div class="text-xs text-zinc-600 dark:text-zinc-400">Hit rate</div><div class="mt-1 text-xl font-semibold text-blue-700 dark:text-blue-300">{{ $rate === null ? '—' : $rate.'%' }}</div></div>
        </div>
        @if ($rate !== null)
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-red-500/30" role="img" aria-label="Cache hit rate {{ $rate }} percent">
                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $rate }}%"></div>
            </div>
        @endif
        @if ($cacheKeys->isNotEmpty())
            <div class="mt-4">
                <div class="mb-1 text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Top missed keys</div>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($cacheKeys as $row)
                        <li class="flex items-center justify-between gap-3 py-1.5 text-xs"><span class="min-w-0 flex-1 truncate font-mono text-zinc-700 dark:text-zinc-300">{{ $row->key }}</span><span class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ (int) $row->count }}×</span></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
