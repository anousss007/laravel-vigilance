@php $ago = fn (?int $ts) => $ts ? \Carbon\CarbonImmutable::createFromTimestamp($ts)->diffForHumans() : '—'; @endphp
<div class="v-card overflow-hidden">
    <div class="v-card__header"><h2 class="v-card__title">Uptime</h2></div>
    <ul>
        @forelse ($endpoints as $endpoint)
            <li class="flex items-center justify-between gap-3 px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="inline-block h-2 w-2 shrink-0 rounded-full" aria-hidden="true" style="background: @if ($endpoint['up'] && $endpoint['fresh']) var(--v-success) @elseif (! $endpoint['up'] && $endpoint['fresh']) var(--v-danger) @else var(--v-faint) @endif;"></span>
                    <span class="truncate font-mono text-xs">{{ $endpoint['url'] }}</span>
                </div>
                <div class="flex shrink-0 items-center gap-2 text-[11px]">
                    <span @class(['v-pill', 'is-success' => $endpoint['up'], 'is-danger' => ! $endpoint['up']])>{{ $endpoint['up'] ? 'up' : 'down' }}{{ $endpoint['status'] ? ' '.$endpoint['status'] : '' }}</span>
                    <span class="v-num v-muted">{{ $endpoint['latency_ms'] }}ms</span>
                    <span class="v-faint">{{ $ago($endpoint['checked_at']) }}</span>
                </div>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-xs v-muted">
                No uptime checks yet. Configure <code class="v-code">vigilance.uptime.urls</code> and schedule <code class="v-code">vigilance:health</code>.
            </li>
        @endforelse
    </ul>
</div>
