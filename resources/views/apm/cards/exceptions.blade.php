@php $ago = fn (?int $ts) => $ts ? \Carbon\CarbonImmutable::createFromTimestamp($ts)->diffForHumans() : '—'; @endphp
<x-vigilance::ui.card variant="sectioned" class="overflow-hidden">
    <x-vigilance::ui.card-header><x-vigilance::ui.card-title>Exceptions</x-vigilance::ui.card-title></x-vigilance::ui.card-header>
    <ul>
        @forelse ($rows as $row)
            @php $k = json_decode($row->key, true) ?: []; @endphp
            <li class="px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                <div class="flex items-center justify-between gap-3">
                    <span class="min-w-0 flex-1 truncate font-medium" style="color: var(--v-danger);">{{ class_basename($k['class'] ?? $row->key) }}</span>
                    <x-vigilance::ui.badge tone="danger" class="shrink-0 v-num">{{ (int) $row->count }}×</x-vigilance::ui.badge>
                </div>
                <div class="mt-0.5 flex items-center justify-between text-[11px] v-faint">
                    <span class="truncate font-mono">{{ $k['location'] ?? '' }}</span><span class="shrink-0">last {{ $ago((int) $row->max) }}</span>
                </div>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-xs v-muted">No exceptions recorded.</li>
        @endforelse
    </ul>
</x-vigilance::ui.card>
