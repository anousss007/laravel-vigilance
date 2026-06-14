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
@endphp

<div wire:poll.visible.5s class="space-y-4">
    <div class="flex items-baseline justify-between">
        <h1 class="text-base font-semibold">Runs</h1>
        <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $runs->total() }} matching</span>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
        <label class="flex flex-col gap-1">
            <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Search</span>
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="name…"
                   class="w-48 rounded border border-zinc-300 bg-transparent px-2 py-1 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700">
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Type</span>
            <select wire:model.live="type" class="rounded border border-zinc-300 bg-transparent px-2 py-1 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900">
                <option value="">All</option>
                @foreach ($types as $t)
                    <option value="{{ $t->value }}">{{ $t->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Status</span>
            <select wire:model.live="status" class="rounded border border-zinc-300 bg-transparent px-2 py-1 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900">
                <option value="">All</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}">{{ ucfirst($s->value) }}</option>
                @endforeach
            </select>
        </label>

        @if (config('vigilance.silence.jobs') || config('vigilance.silence.tags'))
            <label class="flex items-center gap-1.5 self-center text-xs text-zinc-700 dark:text-zinc-300">
                <input type="checkbox" wire:model.live="silenced" class="rounded border-zinc-300 dark:border-zinc-700">
                Show silenced
            </label>
        @endif

        <label class="flex flex-col gap-1">
            <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Queue</span>
            <input type="text" wire:model.live.debounce.300ms="queue" placeholder="default"
                   class="w-32 rounded border border-zinc-300 bg-transparent px-2 py-1 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700">
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Tag</span>
            <input type="text" wire:model.live.debounce.300ms="tag" placeholder="tag"
                   class="w-32 rounded border border-zinc-300 bg-transparent px-2 py-1 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700">
        </label>

        @if ($group)
            <span class="rounded bg-red-500/10 px-2 py-1 text-xs text-red-700 dark:text-red-300">failure group #{{ $group }}</span>
        @endif

        <button type="button" wire:click="clearFilters" class="ml-auto rounded border border-zinc-300 px-2.5 py-1.5 text-xs text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800">
            Clear
        </button>
    </div>

    {{-- Table --}}
    <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="w-full text-left text-xs">
            <thead class="border-b border-zinc-200 text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400 dark:border-zinc-800">
                <tr>
                    <th class="px-3 py-2 font-medium">Status</th>
                    <th class="px-3 py-2 font-medium">Type</th>
                    <th class="px-3 py-2 font-medium">Name</th>
                    <th class="px-3 py-2 font-medium">Queue</th>
                    <th class="px-3 py-2 font-medium">Duration</th>
                    <th class="px-3 py-2 font-medium">Started</th>
                    <th class="px-3 py-2 font-medium">Via</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($runs as $run)
                    <tr wire:key="run-{{ $run->id }}"
                        onclick="window.location='{{ route('vigilance.runs.show', $run->id) }}'"
                        class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-3 py-2">@include('vigilance::partials.status', ['status' => $run->status])</td>
                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $run->type->label() }}</td>
                        <td class="max-w-xs truncate px-3 py-2 font-medium">{{ $run->display_name ?: $run->name }}</td>
                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $run->queue ?: '—' }}</td>
                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $fmtMs($run->duration_ms) }}</td>
                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400" title="{{ $run->started_at }}">{{ optional($run->started_at)->diffForHumans() ?? '—' }}</td>
                        <td class="px-3 py-2">
                            @if ($run->via === 'manual')
                                <span class="rounded bg-blue-500/10 px-1.5 py-0.5 text-[10px] text-blue-700 dark:text-blue-300">manual</span>
                            @else
                                <span class="rounded bg-zinc-500/10 px-1.5 py-0.5 text-[10px] text-zinc-600 dark:text-zinc-400">auto</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-10 text-center text-zinc-600 dark:text-zinc-400">No runs match these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $runs->links('vigilance::pagination') }}</div>
</div>
