<div wire:poll.visible.15s class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Custom Metrics</h1>
            <p class="v-page-sub">Your business KPIs, recorded with <code class="v-code">Vigilance::increment()</code> / <code class="v-code">gauge()</code>.</p>
        </div>
    </div>

    <div class="v-card v-card--pad">
        <div class="flex flex-wrap items-center gap-1">
            @foreach (['1h' => 'Last hour', '24h' => 'Last 24h', '7d' => 'Last 7d'] as $key => $label)
                <button type="button" wire:click="setWindow('{{ $key }}')"
                        @class(['v-btn v-btn--sm', 'v-btn--primary' => $window === $key, 'v-btn--ghost' => $window !== $key])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if ($metrics->isEmpty())
        <div class="v-empty">
            <p class="v-empty__title">No custom metrics yet.</p>
            <p>Record one anywhere in your app: <code class="v-code">Vigilance::increment('signups')</code> or <code class="v-code">Vigilance::gauge('cart_value', 4250)</code>.</p>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($metrics as $metric)
                @php
                    $series = collect($metric->series)->map(fn ($v) => (int) ($v ?? 0))->values();
                    $maxPt = max(1, $series->max() ?? 1);
                    $sw = 280; $sh = 44; $n = max(1, $series->count());
                    $stp = $n > 1 ? $sw / ($n - 1) : $sw;
                    $path = '';
                    foreach ($series as $i => $v) {
                        $x = round($i * $stp, 2);
                        $y = round($sh - (($v / $maxPt) * ($sh - 4)) - 2, 2);
                        $path .= ($i === 0 ? 'M' : 'L').$x.' '.$y.' ';
                    }
                @endphp
                <div class="v-card v-card--pad space-y-2">
                    <div class="flex items-baseline justify-between gap-2">
                        <h2 class="truncate font-mono font-semibold v-strong" title="{{ $metric->name }}">{{ $metric->name }}</h2>
                        <span class="v-pill is-neutral">{{ $metric->type === 'count' ? 'counter' : 'gauge' }}</span>
                    </div>

                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl font-semibold v-strong v-num">{{ number_format($metric->value) }}</span>
                        <span class="text-[11px] v-faint">
                            {{ $metric->type === 'count' ? number_format($metric->peak).' events' : 'peak '.number_format($metric->peak) }}
                        </span>
                    </div>

                    <svg viewBox="0 0 {{ $sw }} {{ $sh }}" preserveAspectRatio="none" class="h-11 w-full" aria-hidden="true">
                        @if ($path)
                            <path d="{{ $path }}" fill="none" stroke="var(--v-accent)" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                        @else
                            <line x1="0" y1="{{ $sh - 2 }}" x2="{{ $sw }}" y2="{{ $sh - 2 }}" stroke="rgb(113 113 122 / 0.4)" stroke-dasharray="3 3" />
                        @endif
                    </svg>
                </div>
            @endforeach
        </div>
    @endif
</div>
