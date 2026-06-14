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
        <h1 class="text-base font-semibold">Schedule</h1>
        <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $tasks->count() }} tasks</span>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="w-full text-left text-xs">
            <thead class="border-b border-zinc-200 text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400 dark:border-zinc-800">
                <tr>
                    <th class="px-3 py-2 font-medium">Task</th>
                    <th class="px-3 py-2 font-medium">Type</th>
                    <th class="px-3 py-2 font-medium">Cron</th>
                    <th class="px-3 py-2 font-medium">Last started</th>
                    <th class="px-3 py-2 font-medium">Last finished</th>
                    <th class="px-3 py-2 font-medium">Duration</th>
                    <th class="px-3 py-2 font-medium">Health</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($tasks as $task)
                    <tr wire:key="task-{{ $task->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="max-w-xs truncate px-3 py-2 font-medium" title="{{ $task->name }}">{{ $task->name }}</td>
                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $task->type ?: '—' }}</td>
                        <td class="px-3 py-2"><code class="rounded bg-zinc-200 px-1 py-0.5 text-[10px] dark:bg-zinc-800">{{ $task->cron_expression ?: '—' }}</code></td>
                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400" title="{{ $task->last_started_at }}">{{ optional($task->last_started_at)->diffForHumans() ?? '—' }}</td>
                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400" title="{{ $task->last_finished_at }}">{{ optional($task->last_finished_at)->diffForHumans() ?? '—' }}</td>
                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $fmtMs($task->last_duration_ms) }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap items-center gap-1">
                                @if ($task->last_run_failed)
                                    <span class="rounded border border-red-500/40 bg-red-500/10 px-1.5 py-0.5 text-[10px] text-red-700 dark:text-red-300">last run failed</span>
                                @endif
                                @if ($task->is_late)
                                    <span class="rounded border border-amber-500/40 bg-amber-500/10 px-1.5 py-0.5 text-[10px] text-amber-700 dark:text-amber-300">late</span>
                                @endif
                                @if (! $task->is_late && ! $task->last_run_failed)
                                    <span class="rounded border border-emerald-500/40 bg-emerald-500/10 px-1.5 py-0.5 text-[10px] text-emerald-700 dark:text-emerald-300">ok</span>
                                @endif
                                @unless ($task->monitored)
                                    <span class="rounded bg-zinc-500/10 px-1.5 py-0.5 text-[10px] text-zinc-600 dark:text-zinc-400">unmonitored</span>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-10 text-center text-zinc-600 dark:text-zinc-400">No scheduled tasks have been recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
