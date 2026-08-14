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

<div @vigilancePoll('5s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Schedule</h1>
            <p class="v-page-sub">Scheduled tasks with last run, duration and lateness.</p>
        </div>
        <x-vigilance::ui.badge tone="neutral" class="v-num">{{ $tasks->count() }} tasks</x-vigilance::ui.badge>
    </div>

    <x-vigilance::ui.card class="overflow-hidden">
        <x-vigilance::ui.table>
                <thead>
                    <tr>
                        <th scope="col">Task</th>
                        <th scope="col">Type</th>
                        <th scope="col">Cron</th>
                        <th scope="col">Last started</th>
                        <th scope="col">Last finished</th>
                        <th scope="col">Duration</th>
                        <th scope="col">Health</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tasks as $task)
                        <tr wire:key="task-{{ $task->id }}">
                            <td class="max-w-xs truncate font-medium v-strong" title="{{ $task->name }}">{{ $task->name }}</td>
                            <td class="v-muted">{{ $task->type ?: '—' }}</td>
                            <td><code class="v-code">{{ $task->cron_expression ?: '—' }}</code></td>
                            <td class="v-muted" title="{{ $task->last_started_at }}">{{ optional($task->last_started_at)->diffForHumans() ?? '—' }}</td>
                            <td class="v-muted" title="{{ $task->last_finished_at }}">{{ optional($task->last_finished_at)->diffForHumans() ?? '—' }}</td>
                            <td class="v-muted font-mono v-num">{{ $fmtMs($task->last_duration_ms) }}</td>
                            <td>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if ($task->last_run_failed)
                                        <x-vigilance::ui.badge tone="danger">last run failed</x-vigilance::ui.badge>
                                    @endif
                                    @if ($task->is_late)
                                        <x-vigilance::ui.badge tone="warning">late</x-vigilance::ui.badge>
                                    @endif
                                    @if (! $task->is_late && ! $task->last_run_failed)
                                        <x-vigilance::ui.badge tone="success">ok</x-vigilance::ui.badge>
                                    @endif
                                    @unless ($task->monitored)
                                        <x-vigilance::ui.badge tone="neutral">unmonitored</x-vigilance::ui.badge>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-10 text-center v-muted">No scheduled tasks have been recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </x-vigilance::ui.table>
    </x-vigilance::ui.card>
</div>
