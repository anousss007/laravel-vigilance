@php
    $total = $cacheHits + $cacheMisses;
    $rate = $total > 0 ? round($cacheHits / $total * 100, 1) : null;
@endphp
<x-vigilance::ui.card variant="sectioned" class="overflow-hidden">
    <x-vigilance::ui.card-header><x-vigilance::ui.card-title>Cache</x-vigilance::ui.card-title></x-vigilance::ui.card-header>
    <div class="p-4">
        <div class="grid grid-cols-1 gap-3 text-center sm:grid-cols-3">
            <div><div class="text-xs v-muted">Hits</div><div class="mt-1 text-xl font-semibold v-num" style="color: var(--v-success);">{{ number_format($cacheHits) }}</div></div>
            <div><div class="text-xs v-muted">Misses</div><div class="mt-1 text-xl font-semibold v-num" style="color: var(--v-danger);">{{ number_format($cacheMisses) }}</div></div>
            <div><div class="text-xs v-muted">Hit rate</div><div class="mt-1 text-xl font-semibold v-num" style="color: var(--v-info);">{{ $rate === null ? '—' : $rate.'%' }}</div></div>
        </div>
        @if ($rate !== null)
            <div class="mt-3 h-2 overflow-hidden rounded-full" role="img" aria-label="Cache hit rate {{ $rate }} percent" style="background: var(--v-surface-2);">
                <div class="h-full rounded-full" style="width: {{ $rate }}%; background: var(--v-accent);"></div>
            </div>
        @endif
        @if ($cacheKeys->isNotEmpty())
            <div class="mt-4">
                <div class="v-stat__label mb-1">Top missed keys</div>
                <ul>
                    @foreach ($cacheKeys as $row)
                        <li class="flex items-center justify-between gap-3 py-1.5 text-xs" style="border-top: 1px solid var(--v-border);"><span class="min-w-0 flex-1 truncate font-mono v-strong">{{ $row->key }}</span><span class="shrink-0 v-faint v-num">{{ (int) $row->count }}×</span></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-vigilance::ui.card>
