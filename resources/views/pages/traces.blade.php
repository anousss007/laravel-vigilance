@php
    $fmtMs = function (int $ms): string {
        if ($ms < 1000) {
            return $ms.'ms';
        }
        return number_format($ms / 1000, 2).'s';
    };

    $typeTone = [
        'request' => 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
        'job' => 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
        'command' => 'bg-zinc-500/10 text-zinc-700 dark:text-zinc-300',
    ];
@endphp

<div class="space-y-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h1 class="text-base font-semibold">Traces</h1>
        <span class="text-xs text-zinc-600 dark:text-zinc-400">request &amp; job timelines (sampled)</span>
    </div>

    @unless ($enabled)
        <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-xs text-amber-800 dark:text-amber-200">
            Tracing is currently disabled. Enable it with <code class="rounded bg-amber-500/10 px-1 py-0.5">VIGILANCE_TRACING=true</code>
            (it stores only slow or failed requests by default). Existing traces below are still browsable.
        </div>
    @endunless

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-1">
            <label for="trace-type" class="text-[11px] text-zinc-600 dark:text-zinc-400">Type</label>
            <select id="trace-type" wire:model.live="type" class="rounded border border-zinc-300 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-950">
                <option value="">All</option>
                <option value="request">Request</option>
                <option value="job">Job</option>
                <option value="command">Command</option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label for="trace-status" class="text-[11px] text-zinc-600 dark:text-zinc-400">Status</label>
            <select id="trace-status" wire:model.live="status" class="rounded border border-zinc-300 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-950">
                <option value="">All</option>
                <option value="ok">OK</option>
                <option value="error">Error</option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label for="trace-q" class="text-[11px] text-zinc-600 dark:text-zinc-400">Search</label>
            <input id="trace-q" type="search" wire:model.live.debounce.400ms="q" placeholder="name…"
                   class="rounded border border-zinc-300 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-950">
        </div>
        <label class="flex items-center gap-1.5 text-xs text-zinc-700 dark:text-zinc-300">
            <input type="checkbox" wire:model.live="slowOnly" class="rounded border-zinc-300 dark:border-zinc-700">
            Slow only
        </label>
        <button type="button" wire:click="clear" class="ml-auto rounded border border-zinc-300 px-2.5 py-1.5 text-xs text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800">
            Clear
        </button>
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <caption class="sr-only">Recent traces</caption>
                <thead class="text-zinc-500 dark:text-zinc-400">
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th scope="col" class="px-4 py-2 font-medium">Type</th>
                        <th scope="col" class="px-4 py-2 font-medium">Name</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Spans</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Duration</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($traces as $trace)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-2">
                                <span class="inline-flex items-center gap-1.5">
                                    <span @class([
                                        'inline-block h-1.5 w-1.5 rounded-full',
                                        'bg-red-500' => $trace->failed(),
                                        'bg-emerald-500' => ! $trace->failed(),
                                    ]) aria-hidden="true"></span>
                                    <span class="rounded px-1.5 py-0.5 text-[10px] {{ $typeTone[$trace->type] ?? 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-300' }}">{{ $trace->type }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-2">
                                <a href="{{ route('vigilance.traces.show', $trace->id) }}" class="font-mono font-medium hover:underline">
                                    {{ $trace->name }}
                                </a>
                                @if ($trace->failed())
                                    <span class="ml-1 text-[10px] text-red-700 dark:text-red-300">failed</span>
                                @endif
                                @if (! empty($trace->attributes['n_plus_one']))
                                    <span class="ml-1 rounded bg-amber-500/10 px-1 py-0.5 text-[9px] text-amber-700 dark:text-amber-300">N+1</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-zinc-600 dark:text-zinc-400">
                                {{ $trace->spanCount }}@if ($trace->droppedSpans > 0)<span class="text-zinc-400" title="dropped at the span cap">+{{ $trace->droppedSpans }}</span>@endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums font-medium {{ $trace->durationMs >= (int) config('vigilance.tracing.slow_threshold', 1000) ? 'text-amber-700 dark:text-amber-300' : '' }}">
                                {{ $fmtMs($trace->durationMs) }}
                            </td>
                            <td class="px-4 py-2 text-right text-zinc-600 dark:text-zinc-400">
                                {{ \Carbon\CarbonImmutable::createFromTimestamp($trace->startedAt)->diffForHumans() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-zinc-600 dark:text-zinc-400">
                                No traces recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
