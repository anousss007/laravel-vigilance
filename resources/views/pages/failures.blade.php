<div wire:poll.visible.5s class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Issues</h1>
            <p class="v-page-sub"><span class="v-num">{{ $groups->total() }}</span> issues · errors across web, queue &amp; commands</p>
        </div>
        @if ($groups->total() > 0)
            <button type="button" wire:click="retryAll" wire:confirm="Retry every failed job?"
                    class="v-btn v-btn--sm">
                @include('vigilance::partials.icon', ['name' => 'failures', 'class' => 'h-4 w-4'])
                Retry all failed
            </button>
        @endif
    </div>

    {{-- Filters --}}
    <div class="v-card v-card--pad">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex gap-1">
                @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All'] as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')"
                            @class(['v-btn v-btn--sm', 'v-btn--primary' => $tab === $key, 'v-btn--ghost' => $tab !== $key])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-1 sm:ml-auto">
                @foreach (['' => 'All sources', 'request' => 'Web', 'job' => 'Jobs', 'command' => 'Commands', 'reported' => 'Reported'] as $key => $label)
                    <button type="button" wire:click="setSource('{{ $key }}')"
                            @class(['v-btn v-btn--sm', 'v-btn--primary' => $source === $key, 'v-btn--ghost' => $source !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="v-card overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="v-table v-table--hover">
                <thead>
                    <tr>
                        <th scope="col">Name / exception</th>
                        <th scope="col">Source</th>
                        <th scope="col">Message</th>
                        <th scope="col">7d</th>
                        <th scope="col" class="text-right">Count</th>
                        <th scope="col">Last seen</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
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
                        <tr wire:key="group-{{ $group->id }}">
                            <td>
                                <a href="{{ route('vigilance.issues.show', $group->id) }}" class="font-medium v-strong hover:underline">{{ $group->name ?: $group->exception_class }}</a>
                                @if ($group->name && $group->exception_class)
                                    <div class="text-[11px] font-mono v-faint">{{ $group->exception_class }}</div>
                                @endif
                            </td>
                            <td><span class="v-pill is-neutral">{{ $group->source ?: $group->type ?: '—' }}</span></td>
                            <td class="max-w-xs truncate v-muted" title="{{ $group->message }}">{{ \Illuminate\Support\Str::limit($group->message, 80) }}</td>
                            <td>
                                @if ($path)
                                    <svg viewBox="0 0 {{ $sw }} {{ $sh }}" class="h-5 w-[70px]"><path d="{{ $path }}" fill="none" stroke="var(--v-danger)" stroke-width="1" vector-effect="non-scaling-stroke" /></svg>
                                @else
                                    <span class="v-faint">—</span>
                                @endif
                            </td>
                            <td class="text-right font-medium v-num">{{ $group->occurrences }}</td>
                            <td class="v-muted" title="{{ $group->last_seen_at }}">{{ optional($group->last_seen_at)->diffForHumans() ?? '—' }}</td>
                            <td>
                                @php $status = $group->status(); @endphp
                                <span @class([
                                    'v-pill',
                                    'is-danger' => $status === 'open',
                                    'is-warn' => $status === 'acknowledged',
                                    'is-info' => $status === 'muted',
                                    'is-success' => $status === 'resolved',
                                ])><span class="v-dot"></span>{{ $status }}</span>
                                @if ($group->priority)
                                    <span @class([
                                        'ml-1 v-pill',
                                        'is-neutral' => in_array($group->priority, ['low', 'normal']),
                                        'is-warn' => $group->priority === 'high',
                                        'is-danger' => $group->priority === 'critical',
                                    ])>{{ $group->priority }}</span>
                                @endif
                                @if ($group->assignee)
                                    <div class="mt-0.5 truncate text-[11px] v-faint">@ {{ $group->assignee }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    <label class="sr-only" for="priority-{{ $group->id }}">Priority for {{ $group->name ?: $group->exception_class }}</label>
                                    <select id="priority-{{ $group->id }}" wire:change="setPriority({{ $group->id }}, $event.target.value)"
                                            class="v-select v-btn--sm w-auto">
                                        @foreach (['low', 'normal', 'high', 'critical'] as $p)
                                            <option value="{{ $p }}" @selected(($group->priority ?: 'normal') === $p)>{{ $p }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="retryGroup({{ $group->id }})" wire:confirm="Retry the failed jobs in this group?" class="v-btn v-btn--sm">Retry</button>
                                    @if (! $group->isResolved() && $group->acknowledged_at === null)
                                        <button type="button" wire:click="acknowledge({{ $group->id }})" class="v-btn v-btn--sm">Ack</button>
                                    @endif
                                    @if ($group->isMuted())
                                        <button type="button" wire:click="unmute({{ $group->id }})" class="v-btn v-btn--sm v-btn--ghost">Unmute</button>
                                    @else
                                        <button type="button" wire:click="mute({{ $group->id }}, 24)" class="v-btn v-btn--sm v-btn--ghost">Mute</button>
                                    @endif
                                    @if ($group->isResolved())
                                        <button type="button" wire:click="reopen({{ $group->id }})" class="v-btn v-btn--sm v-btn--ghost">Reopen</button>
                                    @else
                                        <button type="button" wire:click="resolve({{ $group->id }})" class="v-btn v-btn--sm v-btn--primary">Resolve</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8">
                            <div class="v-empty">
                                <p class="v-empty__title">No issues here.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $groups->links('vigilance::pagination') }}</div>
</div>
