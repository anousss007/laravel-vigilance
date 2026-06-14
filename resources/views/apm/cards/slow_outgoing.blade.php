@php $fmtMs = fn (int $ms) => $ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s'; @endphp
<div class="v-card overflow-hidden">
    <div class="v-card__header"><h2 class="v-card__title">Slow outgoing requests</h2></div>
    <ul>
        @forelse ($rows as $row)
            @php $k = json_decode($row->key, true) ?: []; @endphp
            <li class="flex items-center justify-between gap-3 px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                <div class="min-w-0 flex-1 truncate"><code class="v-code mr-1.5">{{ $k[0] ?? '?' }}</code><span class="font-mono text-xs">{{ $k[1] ?? $row->key }}</span></div>
                <div class="flex shrink-0 items-center gap-2"><span class="text-[11px] v-faint v-num">{{ (int) $row->count }}×</span><span class="v-pill is-warn v-num">{{ $fmtMs((int) $row->max) }}</span></div>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-xs v-muted">No slow outgoing requests recorded.</li>
        @endforelse
    </ul>
</div>
