@php $fmtMs = fn (int $ms) => $ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s'; @endphp
<x-vigilance::ui.card variant="sectioned" class="overflow-hidden">
    <x-vigilance::ui.card-header><x-vigilance::ui.card-title>Slow queries</x-vigilance::ui.card-title></x-vigilance::ui.card-header>
    <ul>
        @forelse ($rows as $row)
            @php $k = json_decode($row->key, true) ?: []; @endphp
            <li class="px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                <div class="flex items-start justify-between gap-3">
                    <code class="min-w-0 flex-1 truncate text-xs font-mono v-strong">{{ $k['sql'] ?? $row->key }}</code>
                    <x-vigilance::ui.badge tone="warning" class="shrink-0 v-num">{{ $fmtMs((int) $row->max) }}</x-vigilance::ui.badge>
                </div>
                <div class="mt-0.5 flex items-center justify-between text-[11px] v-faint">
                    <span class="truncate font-mono">{{ $k['location'] ?? '' }}</span><span class="shrink-0 v-num">{{ (int) $row->count }}×</span>
                </div>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-xs v-muted">No slow queries recorded.</li>
        @endforelse
    </ul>
</x-vigilance::ui.card>
