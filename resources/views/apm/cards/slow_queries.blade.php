@php $fmtMs = fn (int $ms) => $ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s'; @endphp
<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800"><h2 class="text-sm font-semibold">Slow queries</h2></div>
    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
        @forelse ($rows as $row)
            @php $k = json_decode($row->key, true) ?: []; @endphp
            <li class="px-4 py-2.5">
                <div class="flex items-start justify-between gap-3">
                    <code class="min-w-0 flex-1 truncate text-xs text-zinc-700 dark:text-zinc-300">{{ $k['sql'] ?? $row->key }}</code>
                    <span class="shrink-0 rounded bg-amber-500/10 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">{{ $fmtMs((int) $row->max) }}</span>
                </div>
                <div class="mt-0.5 flex items-center justify-between text-[11px] text-zinc-500 dark:text-zinc-400">
                    <span class="truncate font-mono">{{ $k['location'] ?? '' }}</span><span class="shrink-0">{{ (int) $row->count }}×</span>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-xs text-zinc-600 dark:text-zinc-400">No slow queries recorded.</li>
        @endforelse
    </ul>
</div>
