<div class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Application performance</h1>
            <p class="v-page-sub">Whole-app telemetry — servers, requests, queries, cache, exceptions and more.</p>
        </div>

        <div class="inline-flex items-center gap-0.5 rounded-lg p-0.5" role="group" aria-label="Time window"
             style="background: var(--v-surface-2); border: 1px solid var(--v-border);">
            @foreach ($periods as $p)
                <button type="button"
                        wire:click="setPeriod('{{ $p }}')"
                        aria-pressed="{{ $period === $p ? 'true' : 'false' }}"
                        class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                        @if ($period === $p)
                            style="background: var(--v-surface); color: var(--v-text-strong); box-shadow: var(--v-shadow);"
                        @else
                            style="color: var(--v-muted);"
                        @endif>
                    {{ $p }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Card layout — publish "vigilance-views" to customise (rearrange / resize / add cards). --}}
    @include('vigilance::apm-dashboard', ['period' => $period])
</div>
