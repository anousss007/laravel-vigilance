@php $fmtMs = fn (int $ms) => $ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s'; @endphp
<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
        <h2 class="text-sm font-semibold">Slow jobs</h2>
        <a href="{{ route('vigilance.runs') }}" class="text-xs text-emerald-700 hover:underline dark:text-emerald-300">view runs</a>
    </div>
    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
        @forelse ($rows as $row)
            <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                <span class="min-w-0 flex-1 truncate font-mono font-medium">{{ $row->key }}</span>
                <div class="flex shrink-0 items-center gap-2"><span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ (int) $row->count }}×</span><span class="rounded bg-amber-500/10 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">{{ $fmtMs((int) $row->max) }}</span></div>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-xs text-zinc-600 dark:text-zinc-400">
                No slow jobs recorded yet.
                @if (count($recent) > 0)
                    <span class="mt-2 block text-zinc-500">Recent slowest runs: @foreach ($recent as $run)<a href="{{ route('vigilance.runs.show', $run->id) }}" class="underline">{{ class_basename($run->name) }}</a>@if (! $loop->last), @endif @endforeach</span>
                @endif
            </li>
        @endforelse
    </ul>
</div>
