<div class="space-y-6">
    <div class="v-page-head">
        <div class="min-w-0">
            <a href="{{ route('vigilance.issues') }}" class="text-xs v-link">&larr; All issues</a>
            <h1 class="v-page-title mt-1 break-words">{{ $issue->name ?: $issue->exception_class ?: 'Issue' }}</h1>
            @if ($issue->exception_class)
                <p class="v-page-sub font-mono">{{ $issue->exception_class }}</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-1">
            <label class="sr-only" for="issue-priority">Priority</label>
            <select id="issue-priority" wire:change="setPriority($event.target.value)" class="v-select v-btn--sm w-auto">
                @foreach (['low', 'normal', 'high', 'critical'] as $p)
                    <option value="{{ $p }}" @selected(($issue->priority ?: 'normal') === $p)>{{ $p }}</option>
                @endforeach
            </select>
            @if ($runs->isNotEmpty())
                <button type="button" wire:click="retryGroup" wire:confirm="Retry the failed jobs in this group?" class="v-btn v-btn--sm">Retry</button>
            @endif
            @if (! $issue->isResolved() && $issue->acknowledged_at === null)
                <button type="button" wire:click="acknowledge" class="v-btn v-btn--sm">Ack</button>
            @endif
            @if ($issue->isMuted())
                <button type="button" wire:click="unmute" class="v-btn v-btn--sm v-btn--ghost">Unmute</button>
            @else
                <button type="button" wire:click="mute(24)" class="v-btn v-btn--sm v-btn--ghost">Mute</button>
            @endif
            @if ($issue->isResolved())
                <button type="button" wire:click="reopen" class="v-btn v-btn--sm v-btn--ghost">Reopen</button>
            @else
                <button type="button" wire:click="resolve" class="v-btn v-btn--sm v-btn--primary">Resolve</button>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @php $status = $issue->status(); @endphp
        <div class="v-stat">
            <div class="v-stat__label">Status</div>
            <div class="mt-2">
                <span @class(['v-pill', 'is-danger' => $status === 'open', 'is-warn' => $status === 'acknowledged', 'is-info' => $status === 'muted', 'is-success' => $status === 'resolved'])><span class="v-dot"></span>{{ $status }}</span>
            </div>
        </div>
        <div class="v-stat"><div class="v-stat__label">Occurrences</div><div class="v-stat__value v-num">{{ $issue->occurrences }}</div></div>
        <div class="v-stat"><div class="v-stat__label">Source</div><div class="mt-2"><span class="v-pill is-neutral">{{ $issue->source ?: $issue->type ?: '—' }}</span></div></div>
        <div class="v-stat"><div class="v-stat__label">Last seen</div><div class="mt-1.5 text-base font-semibold v-strong" title="{{ $issue->last_seen_at }}">{{ optional($issue->last_seen_at)->diffForHumans() ?? '—' }}</div></div>
    </div>

    @if ($issue->message)
        <div class="v-card v-card--pad">
            <div class="v-stat__label mb-1">Message</div>
            <p class="break-words font-mono text-[13px] v-strong">{{ $issue->message }}</p>
        </div>
    @endif

    @php $breadcrumbs = $issue->context['breadcrumbs'] ?? []; @endphp

    @if (! empty(array_diff_key((array) $issue->context, ['breadcrumbs' => true])))
        <div class="v-card">
            <div class="v-card__header"><h2 class="v-card__title">Context</h2></div>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 p-4 sm:grid-cols-2">
                @foreach ($issue->context as $key => $value)
                    @continue($key === 'breadcrumbs')
                    <div class="flex gap-3">
                        <dt class="v-stat__label w-20 shrink-0">{{ $key }}</dt>
                        <dd class="min-w-0 break-words font-mono text-[12px] v-muted">{{ is_array($value) ? json_encode($value) : $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    @if (! empty($breadcrumbs))
        <div class="v-card overflow-hidden">
            <div class="v-card__header">
                <h2 class="v-card__title">Breadcrumbs</h2>
                <span class="text-[10px] uppercase tracking-wide v-faint">trail before the error</span>
            </div>
            <ol class="divide-y">
                @foreach ($breadcrumbs as $crumb)
                    @php
                        $lvl = strtolower($crumb['level'] ?? 'info');
                        $tone = match ($lvl) {
                            'error', 'critical', 'alert', 'emergency' => 'is-danger',
                            'warning' => 'is-warn',
                            'debug' => 'is-neutral',
                            default => 'is-info',
                        };
                    @endphp
                    <li class="flex items-start gap-3 px-4 py-2 text-[12px]">
                        <span class="v-pill {{ $tone }} shrink-0 uppercase tracking-wide">{{ $lvl }}</span>
                        @if (! empty($crumb['category']))
                            <span class="shrink-0 font-mono v-faint">{{ $crumb['category'] }}</span>
                        @endif
                        <span class="min-w-0 break-words v-strong">{{ $crumb['message'] ?? '' }}</span>
                        @if (! empty($crumb['data']))
                            <span class="min-w-0 break-words font-mono v-muted">{{ json_encode($crumb['data']) }}</span>
                        @endif
                        <span class="ml-auto shrink-0 font-mono v-faint">{{ \Illuminate\Support\Str::after((string) ($crumb['t'] ?? ''), 'T') }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @if ($issue->sample)
        <div class="v-card overflow-hidden">
            <div class="v-card__header"><h2 class="v-card__title">Stack trace</h2></div>
            <pre tabindex="0" class="max-h-96 overflow-auto p-4 font-mono text-[12px] leading-relaxed v-muted">{{ $issue->sample }}</pre>
        </div>
    @endif

    @if ($runs->isNotEmpty())
        <div class="v-card overflow-hidden">
            <div class="v-card__header"><h2 class="v-card__title">Recent runs</h2></div>
            <div class="overflow-x-auto" tabindex="0">
                <table class="v-table v-table--hover">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Queue</th>
                            <th scope="col">When</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr wire:key="run-{{ $run->id }}" onclick="window.location='{{ route('vigilance.runs.show', $run->id) }}'" class="cursor-pointer">
                                <td class="font-mono v-strong">{{ $run->name }}</td>
                                <td class="v-muted">{{ $run->queue ?: '—' }}</td>
                                <td class="v-muted" title="{{ $run->created_at }}">{{ optional($run->created_at)->diffForHumans() }}</td>
                                <td>@include('vigilance::partials.status', ['status' => $run->status])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
