@php $fmtMs = fn (int $ms) => $ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s'; @endphp
<div class="v-card overflow-hidden">
    <div class="v-card__header"><h2 class="v-card__title">Slow queries</h2></div>
    <ul>
        @forelse ($rows as $row)
            @php $k = json_decode($row->key, true) ?: []; @endphp
            <li class="px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                <div class="flex items-start justify-between gap-3">
                    <code class="min-w-0 flex-1 truncate text-xs font-mono v-strong">{{ $k['sql'] ?? $row->key }}</code>
                    <span class="v-pill is-warn shrink-0 v-num">{{ $fmtMs((int) $row->max) }}</span>
                </div>
                <div class="mt-0.5 flex items-center justify-between text-[11px] v-faint">
                    <span class="truncate font-mono">{{ $k['location'] ?? '' }}</span><span class="shrink-0 v-num">{{ (int) $row->count }}×</span>
                </div>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-xs v-muted">No slow queries recorded.</li>
        @endforelse
    </ul>
</div>
