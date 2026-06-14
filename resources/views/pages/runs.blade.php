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
            <h1 class="v-page-title">Runs</h1>
            <p class="v-page-sub">Captured job, command and scheduled-task runs.</p>
        </div>
        <span class="text-xs v-muted v-num">{{ $runs->total() }} matching</span>
    </div>

    {{-- Filters --}}
    <div class="v-card v-card--pad">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="v-label">Search</label>
                <input type="search" wire:model.live.debounce.300ms="q" placeholder="name…" class="v-input w-48">
            </div>

            <div>
                <label class="v-label">Type</label>
                <select wire:model.live="type" class="v-select">
                    <option value="">All</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="v-label">Status</label>
                <select wire:model.live="status" class="v-select">
                    <option value="">All</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ ucfirst($s->value) }}</option>
                    @endforeach
                </select>
            </div>

            @if (config('vigilance.silence.jobs') || config('vigilance.silence.tags'))
                <label class="flex items-center gap-1.5 self-center text-xs v-muted">
                    <input type="checkbox" wire:model.live="silenced" class="v-checkbox">
                    Show silenced
                </label>
            @endif

            <div>
                <label class="v-label">Queue</label>
                <input type="text" wire:model.live.debounce.300ms="queue" placeholder="default" class="v-input w-32">
            </div>

            <div>
                <label class="v-label">Tag</label>
                <input type="text" wire:model.live.debounce.300ms="tag" placeholder="tag" class="v-input w-32">
            </div>

            @if ($group)
                <span class="v-pill is-danger v-num self-center">failure group #{{ $group }}</span>
            @endif

            <button type="button" wire:click="clearFilters" class="v-btn v-btn--sm ml-auto">
                Clear
            </button>
        </div>
    </div>

    {{-- Table --}}
    <div class="v-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="v-table v-table--hover">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Queue</th>
                        <th>Duration</th>
                        <th>Started</th>
                        <th>Via</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr wire:key="run-{{ $run->id }}"
                            onclick="window.location='{{ route('vigilance.runs.show', $run->id) }}'"
                            class="cursor-pointer">
                            <td>@include('vigilance::partials.status', ['status' => $run->status])</td>
                            <td class="v-muted">{{ $run->type->label() }}</td>
                            <td class="max-w-xs truncate font-medium v-strong">{{ $run->display_name ?: $run->name }}</td>
                            <td class="v-muted font-mono">{{ $run->queue ?: '—' }}</td>
                            <td class="v-muted v-num">{{ $fmtMs($run->duration_ms) }}</td>
                            <td class="v-muted v-num" title="{{ $run->started_at }}">{{ optional($run->started_at)->diffForHumans() ?? '—' }}</td>
                            <td>
                                @if ($run->via === 'manual')
                                    <span class="v-pill is-info">manual</span>
                                @else
                                    <span class="v-pill is-neutral">auto</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-10 text-center v-muted">No runs match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $runs->links('vigilance::pagination') }}</div>
</div>
