<div @vigilancePoll('15s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Custom Metrics</h1>
            <p class="v-page-sub">Your business KPIs, recorded with <code class="v-code">Vigilance::increment()</code> / <code class="v-code">gauge()</code>.</p>
        </div>
    </div>

    <x-vigilance::ui.card class="p-2">
        <x-vigilance::range-picker :ranges="$this->ranges()" :labels="$this->rangeLabels()" :current="$range" />
    </x-vigilance::ui.card>

    @if ($metrics->isEmpty())
        <x-vigilance::ui.empty>
    <x-vigilance::ui.empty-title>No custom metrics yet.</x-vigilance::ui.empty-title>
    <x-vigilance::ui.empty-description><p>Record one anywhere in your app: <code class="v-code">Vigilance::increment('signups')</code> or <code class="v-code">Vigilance::gauge('cart_value', 4250)</code>.</p></x-vigilance::ui.empty-description>
</x-vigilance::ui.empty>
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
                <x-vigilance::ui.card class="space-y-2">
                    <div class="flex items-baseline justify-between gap-2">
                        <h2 class="truncate font-mono font-semibold v-strong" title="{{ $metric->name }}">{{ $metric->name }}</h2>
                        <x-vigilance::ui.badge tone="neutral">{{ ['count' => 'counter', 'value' => 'gauge', 'distribution' => 'distribution'][$metric->type] ?? $metric->type }}</x-vigilance::ui.badge>
                    </div>

                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl font-semibold v-strong v-num">{{ number_format($metric->value) }}</span>
                        <span class="text-[11px] v-faint">
                            @switch($metric->type)
                                @case('count') {{ number_format($metric->peak) }} events @break
                                @case('distribution') avg · peak {{ number_format($metric->peak) }} @break
                                @default peak {{ number_format($metric->peak) }}
                            @endswitch
                        </span>
                    </div>

                    @if ($metric->type === 'distribution')
                        <div class="flex gap-3 text-[11px] v-muted font-mono">
                            <span>p50 <span class="v-strong v-num">{{ $metric->p50 ?? '—' }}</span></span>
                            <span>p95 <span class="v-strong v-num">{{ $metric->p95 ?? '—' }}</span></span>
                            <span>p99 <span class="v-strong v-num">{{ $metric->p99 ?? '—' }}</span></span>
                        </div>
                    @endif

                    <svg viewBox="0 0 {{ $sw }} {{ $sh }}" preserveAspectRatio="none" class="h-11 w-full" aria-hidden="true">
                        @if ($path)
                            <path d="{{ $path }}" fill="none" stroke="var(--v-accent)" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                        @else
                            <line x1="0" y1="{{ $sh - 2 }}" x2="{{ $sw }}" y2="{{ $sh - 2 }}" stroke="rgb(113 113 122 / 0.4)" stroke-dasharray="3 3" />
                        @endif
                    </svg>
                </x-vigilance::ui.card>
            @endforeach
        </div>
    @endif
</div>
