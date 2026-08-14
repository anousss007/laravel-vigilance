@php $fmtMs = fn (int $ms) => $ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s'; @endphp
<x-vigilance::ui.card variant="sectioned" class="overflow-hidden">
    <x-vigilance::ui.card-header><x-vigilance::ui.card-title>Slow outgoing requests</x-vigilance::ui.card-title></x-vigilance::ui.card-header>
    <ul>
        @forelse ($rows as $row)
            @php $k = json_decode($row->key, true) ?: []; @endphp
            <li class="flex items-center justify-between gap-3 px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                <div class="min-w-0 flex-1 truncate"><code class="v-code mr-1.5">{{ $k[0] ?? '?' }}</code><span class="font-mono text-xs">{{ $k[1] ?? $row->key }}</span></div>
                <div class="flex shrink-0 items-center gap-2"><span class="text-[11px] v-faint v-num">{{ (int) $row->count }}×</span><x-vigilance::ui.badge tone="warning" class="v-num">{{ $fmtMs((int) $row->max) }}</x-vigilance::ui.badge></div>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-xs v-muted">No slow outgoing requests recorded.</li>
        @endforelse
    </ul>
</x-vigilance::ui.card>
