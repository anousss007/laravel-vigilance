@php $ago = fn (?int $ts) => $ts ? \Carbon\CarbonImmutable::createFromTimestamp($ts)->diffForHumans() : '—'; @endphp
<div class="v-card overflow-hidden">
    <div class="v-card__header"><h2 class="v-card__title">Exceptions</h2></div>
    <ul>
        @forelse ($rows as $row)
            @php $k = json_decode($row->key, true) ?: []; @endphp
            <li class="px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                <div class="flex items-center justify-between gap-3">
                    <span class="min-w-0 flex-1 truncate font-medium" style="color: var(--v-danger);">{{ class_basename($k['class'] ?? $row->key) }}</span>
                    <span class="v-pill is-danger shrink-0 v-num">{{ (int) $row->count }}×</span>
                </div>
                <div class="mt-0.5 flex items-center justify-between text-[11px] v-faint">
                    <span class="truncate font-mono">{{ $k['location'] ?? '' }}</span><span class="shrink-0">last {{ $ago((int) $row->max) }}</span>
                </div>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-xs v-muted">No exceptions recorded.</li>
        @endforelse
    </ul>
</div>
