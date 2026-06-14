<div wire:poll.visible.5s class="space-y-4">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div class="flex items-baseline gap-3">
            <h1 class="text-base font-semibold">Failures</h1>
            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $groups->total() }} groups</span>
        </div>
        @if ($groups->total() > 0)
            <button type="button" wire:click="retryAll" wire:confirm="Retry every failed job?"
                    class="rounded border border-zinc-300 px-2.5 py-1 text-xs text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                Retry all failed
            </button>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 rounded-lg border border-zinc-200 bg-white p-1 text-xs dark:border-zinc-800 dark:bg-zinc-900">
        @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                    @class([
                        'rounded px-3 py-1.5 transition-colors',
                        'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' => $tab === $key,
                        'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800' => $tab !== $key,
                    ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="w-full text-left text-xs">
            <thead class="border-b border-zinc-200 text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400 dark:border-zinc-800">
                <tr>
                    <th class="px-3 py-2 font-medium">Name / exception</th>
                    <th class="px-3 py-2 font-medium">Message</th>
                    <th class="px-3 py-2 font-medium">7d</th>
                    <th class="px-3 py-2 text-right font-medium">Count</th>
                    <th class="px-3 py-2 font-medium">Last seen</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                    <th class="px-3 py-2 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($groups as $group)
                    @php
                        $series = $sparklines[$group->id] ?? [];
                        $maxPt = max(1, count($series) ? max($series) : 1);
                        $sw = 70; $sh = 20; $n = max(1, count($series));
                        $stp = $n > 1 ? $sw / ($n - 1) : $sw;
                        $path = '';
                        foreach (array_values($series) as $i => $v) {
                            $x = round($i * $stp, 2);
                            $y = round($sh - (($v / $maxPt) * ($sh - 2)) - 1, 2);
                            $path .= ($i === 0 ? 'M' : 'L').$x.' '.$y.' ';
                        }
                    @endphp
                    <tr wire:key="group-{{ $group->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-3 py-2">
                            <a href="{{ route('vigilance.runs', ['group' => $group->id]) }}" class="font-medium hover:underline">{{ $group->name ?: $group->exception_class }}</a>
                            @if ($group->name && $group->exception_class)
                                <div class="text-[10px] text-zinc-600 dark:text-zinc-400">{{ $group->exception_class }}</div>
                            @endif
                        </td>
                        <td class="max-w-xs truncate px-3 py-2 text-zinc-600 dark:text-zinc-400" title="{{ $group->message }}">{{ \Illuminate\Support\Str::limit($group->message, 80) }}</td>
                        <td class="px-3 py-2">
                            @if ($path)
                                <svg viewBox="0 0 {{ $sw }} {{ $sh }}" class="h-5 w-[70px]"><path d="{{ $path }}" fill="none" stroke="rgb(239 68 68)" stroke-width="1" vector-effect="non-scaling-stroke" /></svg>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right font-medium">{{ $group->occurrences }}</td>
                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400" title="{{ $group->last_seen_at }}">{{ optional($group->last_seen_at)->diffForHumans() ?? '—' }}</td>
                        <td class="px-3 py-2">
                            @php $status = $group->status(); @endphp
                            <span @class([
                                'rounded px-1.5 py-0.5 text-[10px]',
                                'bg-red-500/10 text-red-700 dark:text-red-300' => $status === 'open',
                                'bg-amber-500/10 text-amber-700 dark:text-amber-300' => $status === 'acknowledged',
                                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $status === 'resolved',
                            ])>{{ $status }}</span>
                            @if ($group->priority)
                                <span @class([
                                    'ml-1 rounded px-1.5 py-0.5 text-[10px]',
                                    'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' => in_array($group->priority, ['low', 'normal']),
                                    'bg-orange-500/10 text-orange-700 dark:text-orange-300' => $group->priority === 'high',
                                    'bg-red-500/15 text-red-700 dark:text-red-300' => $group->priority === 'critical',
                                ])>{{ $group->priority }}</span>
                            @endif
                            @if ($group->assignee)
                                <div class="mt-0.5 truncate text-[10px] text-zinc-500 dark:text-zinc-400">@ {{ $group->assignee }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex items-center justify-end gap-1">
                                <label class="sr-only" for="priority-{{ $group->id }}">Priority for {{ $group->name ?: $group->exception_class }}</label>
                                <select id="priority-{{ $group->id }}" wire:change="setPriority({{ $group->id }}, $event.target.value)"
                                        class="rounded border border-zinc-300 bg-white px-1 py-1 text-[10px] dark:border-zinc-700 dark:bg-zinc-950">
                                    @foreach (['low', 'normal', 'high', 'critical'] as $p)
                                        <option value="{{ $p }}" @selected(($group->priority ?: 'normal') === $p)>{{ $p }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="retryGroup({{ $group->id }})" wire:confirm="Retry the failed jobs in this group?" class="rounded border border-zinc-300 px-2 py-1 text-[10px] text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">Retry</button>
                                @if (! $group->isResolved() && $group->acknowledged_at === null)
                                    <button type="button" wire:click="acknowledge({{ $group->id }})" class="rounded border border-amber-500/40 bg-amber-500/10 px-2 py-1 text-[10px] text-amber-700 hover:bg-amber-500/20 dark:text-amber-300">Ack</button>
                                @endif
                                @if ($group->isResolved())
                                    <button type="button" wire:click="reopen({{ $group->id }})" class="rounded border border-zinc-300 px-2 py-1 text-[10px] text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800">Reopen</button>
                                @else
                                    <button type="button" wire:click="resolve({{ $group->id }})" class="rounded border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 text-[10px] text-emerald-700 hover:bg-emerald-500/20 dark:text-emerald-300">Resolve</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-10 text-center text-zinc-600 dark:text-zinc-400">No failure groups here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $groups->links('vigilance::pagination') }}</div>
</div>
