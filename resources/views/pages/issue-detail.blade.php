<div class="space-y-6">
    <div class="v-page-head">
        <div class="min-w-0">
            <a href="{{ route('vigilance.issues') }}" class="text-xs v-link">&larr; All issues</a>
            @php
                // Title by the (root) exception, Sentry-style: "Error: <message>".
                $title = $issue->exception_class
                    ? $issue->exception_class.($issue->message ? ': '.\Illuminate\Support\Str::limit($issue->message, 140) : '')
                    : ($issue->name ?: 'Issue');
                $culprit = $issue->context['culprit'] ?? null;
            @endphp
            <h1 class="v-page-title mt-1 break-words">{{ $title }}</h1>
            @if ($issue->name && $issue->exception_class)
                <p class="v-page-sub font-mono break-words">{{ $issue->name }}</p>
            @endif
            @if ($culprit)
                <p class="v-page-sub font-mono break-words text-[12px]">in {{ $culprit }}</p>
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
                <x-vigilance::ui.button variant="outline" size="sm" wire:click="retryGroup" wire:confirm="Retry the failed jobs in this group?">Retry</x-vigilance::ui.button>
            @endif
            @if (! $issue->isResolved() && $issue->acknowledged_at === null)
                <x-vigilance::ui.button variant="outline" size="sm" wire:click="acknowledge">Ack</x-vigilance::ui.button>
            @endif
            @if ($issue->isMuted())
                <x-vigilance::ui.button variant="ghost" size="sm" wire:click="unmute">Unmute</x-vigilance::ui.button>
            @else
                <x-vigilance::ui.button variant="ghost" size="sm" wire:click="mute(24)">Mute</x-vigilance::ui.button>
            @endif
            @if ($issue->isResolved())
                <x-vigilance::ui.button variant="ghost" size="sm" wire:click="reopen">Reopen</x-vigilance::ui.button>
            @else
                <x-vigilance::ui.button size="sm" wire:click="resolve">Resolve</x-vigilance::ui.button>
            @endif
            <form wire:submit.prevent="merge" class="flex items-center gap-1">
                <input type="number" min="1" wire:model="mergeInto" placeholder="Merge into #" class="v-select v-btn--sm w-28" aria-label="Merge into issue id">
                <x-vigilance::ui.button variant="ghost" size="sm" type="submit">Merge</x-vigilance::ui.button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @php $status = $issue->status(); @endphp
        <x-vigilance::ui.card>
            <div class="v-stat__label">Status</div>
            <div class="mt-2">
                <span @class(['v-pill', 'is-danger' => $status === 'open', 'is-warn' => $status === 'acknowledged', 'is-info' => $status === 'muted', 'is-success' => $status === 'resolved'])><span class="v-dot"></span>{{ $status }}</span>
            </div>
        </x-vigilance::ui.card>
        <x-vigilance::ui.card><div class="v-stat__label">Occurrences</div><div class="v-stat__value v-num">{{ $issue->occurrences }}</div></x-vigilance::ui.card>
        <x-vigilance::ui.card><div class="v-stat__label">Source</div><div class="mt-2"><x-vigilance::ui.badge tone="neutral">{{ $issue->source ?: $issue->type ?: '—' }}</x-vigilance::ui.badge></div></x-vigilance::ui.card>
        <x-vigilance::ui.card><div class="v-stat__label">Last seen</div><div class="mt-1.5 text-base font-semibold v-strong" title="{{ $issue->last_seen_at }}">{{ optional($issue->last_seen_at)->diffForHumans() ?? '—' }}</div></x-vigilance::ui.card>
    </div>

    @if ($issue->message)
        <x-vigilance::ui.card>
            <div class="v-stat__label mb-1">Message</div>
            <p class="break-words font-mono text-[13px] v-strong">{{ $issue->message }}</p>
        </x-vigilance::ui.card>
    @endif

    @php $breadcrumbs = $issue->context['breadcrumbs'] ?? []; @endphp

    @if (! empty(array_diff_key((array) $issue->context, ['breadcrumbs' => true])))
        <x-vigilance::ui.card variant="sectioned">
            <x-vigilance::ui.card-header><x-vigilance::ui.card-title>Context</x-vigilance::ui.card-title></x-vigilance::ui.card-header>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 p-4 sm:grid-cols-2">
                @foreach ($issue->context as $key => $value)
                    @continue($key === 'breadcrumbs')
                    <div class="flex gap-3">
                        <dt class="v-stat__label w-20 shrink-0">{{ $key }}</dt>
                        <dd class="min-w-0 break-words font-mono text-[12px] v-muted">{{ is_array($value) ? json_encode($value) : $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-vigilance::ui.card>
    @endif

    @if (! empty($breadcrumbs))
        <x-vigilance::ui.card variant="sectioned" class="overflow-hidden">
            <x-vigilance::ui.card-header>
                <x-vigilance::ui.card-title>Breadcrumbs</x-vigilance::ui.card-title>
                <span class="text-[10px] uppercase tracking-wide v-faint">trail before the error</span>
            </x-vigilance::ui.card-header>
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
        </x-vigilance::ui.card>
    @endif

    @if ($issue->sample)
        <x-vigilance::ui.card variant="sectioned" class="overflow-hidden">
            <x-vigilance::ui.card-header><x-vigilance::ui.card-title>Stack trace</x-vigilance::ui.card-title></x-vigilance::ui.card-header>
            <pre tabindex="0" class="max-h-96 overflow-auto p-4 font-mono text-[12px] leading-relaxed v-muted">{{ $issue->sample }}</pre>
        </x-vigilance::ui.card>
    @endif

    @if ($runs->isNotEmpty())
        <x-vigilance::ui.card variant="sectioned" class="overflow-hidden">
            <x-vigilance::ui.card-header><x-vigilance::ui.card-title>Recent runs</x-vigilance::ui.card-title></x-vigilance::ui.card-header>
            <x-vigilance::ui.table>
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
            </x-vigilance::ui.table>
        </x-vigilance::ui.card>
    @endif
</div>
