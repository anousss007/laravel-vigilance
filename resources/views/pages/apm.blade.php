<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-base font-semibold">Application performance</h1>

        <div class="flex items-center gap-1" role="group" aria-label="Time window">
            @foreach ($periods as $p)
                <button type="button"
                        wire:click="setPeriod('{{ $p }}')"
                        aria-pressed="{{ $period === $p ? 'true' : 'false' }}"
                        @class([
                            'rounded px-2.5 py-1 text-xs transition-colors',
                            'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' => $period === $p,
                            'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100' => $period !== $p,
                        ])>
                    {{ $p }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Card layout — publish "vigilance-views" to customise (rearrange / resize / add cards). --}}
    @include('vigilance::apm-dashboard', ['period' => $period])
</div>
