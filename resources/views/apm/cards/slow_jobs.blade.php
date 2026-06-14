@php $fmtMs = fn (int $ms) => $ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s'; @endphp
<div class="v-card overflow-hidden">
    <div class="v-card__header">
        <h2 class="v-card__title">Slow jobs</h2>
        <a href="{{ route('vigilance.runs') }}" class="text-xs v-link">view runs</a>
    </div>
    <ul>
        @forelse ($rows as $row)
            <li class="flex items-center justify-between gap-3 px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                <span class="min-w-0 flex-1 truncate font-mono font-medium v-strong">{{ $row->key }}</span>
                <div class="flex shrink-0 items-center gap-2"><span class="text-[11px] v-faint v-num">{{ (int) $row->count }}×</span><span class="v-pill is-warn v-num">{{ $fmtMs((int) $row->max) }}</span></div>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-xs v-muted">
                No slow jobs recorded yet.
                @if (count($recent) > 0)
                    <span class="mt-2 block v-faint">Recent slowest runs: @foreach ($recent as $run)<a href="{{ route('vigilance.runs.show', $run->id) }}" class="v-link">{{ class_basename($run->name) }}</a>@if (! $loop->last), @endif @endforeach</span>
                @endif
            </li>
        @endforelse
    </ul>
</div>
