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

<div wire:poll.visible.5s class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Schedule</h1>
            <p class="v-page-sub">Scheduled tasks with last run, duration and lateness.</p>
        </div>
        <span class="v-pill is-neutral v-num">{{ $tasks->count() }} tasks</span>
    </div>

    <div class="v-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="v-table v-table--hover">
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
                                        <span class="v-pill is-danger">last run failed</span>
                                    @endif
                                    @if ($task->is_late)
                                        <span class="v-pill is-warn">late</span>
                                    @endif
                                    @if (! $task->is_late && ! $task->last_run_failed)
                                        <span class="v-pill is-success">ok</span>
                                    @endif
                                    @unless ($task->monitored)
                                        <span class="v-pill is-neutral">unmonitored</span>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-10 text-center v-muted">No scheduled tasks have been recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
