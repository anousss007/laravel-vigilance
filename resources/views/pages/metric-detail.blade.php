@php
    $fmtMs = fn (?int $ms) => $ms === null ? '—' : ($ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s');

    $points = collect($series);

    // Build an inline SVG area+line chart from a numeric accessor over the series.
    $chart = function (callable $value, string $stroke, string $fill) use ($points) {
        $vals = $points->map($value)->map(fn ($v) => (float) ($v ?? 0))->values();
        if ($vals->isEmpty()) {
            return null;
        }
        $max = max(1.0, (float) $vals->max());
        $w = 600;
        $h = 70;
        $n = max(1, $vals->count());
        $step = $n > 1 ? $w / ($n - 1) : $w;
        $line = '';
        $area = '';
        foreach ($vals as $i => $v) {
            $x = round($i * $step, 2);
            $y = round($h - ($v / $max) * ($h - 8) - 4, 2);
            $line .= ($i === 0 ? 'M' : 'L').$x.' '.$y.' ';
            $area .= ($i === 0 ? 'M'.$x.' '.$h.' L'.$x.' '.$y.' ' : 'L'.$x.' '.$y.' ');
        }
        $area .= 'L'.round(($n - 1) * $step, 2).' '.$h.' Z';

        return ['line' => $line, 'area' => $area, 'w' => $w, 'h' => $h, 'stroke' => $stroke, 'fill' => $fill, 'max' => $max];
    };

    $charts = [
        ['title' => 'Throughput', 'unit' => 'runs/snapshot', 'c' => $chart(fn ($p) => $p->throughput, 'rgb(16 185 129)', 'rgb(16 185 129 / 0.12)'), 'fmt' => fn ($v) => number_format((int) $v)],
        ['title' => 'Avg runtime', 'unit' => '', 'c' => $chart(fn ($p) => $p->runtime_avg_ms, 'rgb(59 130 246)', 'rgb(59 130 246 / 0.12)'), 'fmt' => $fmtMs],
        ['title' => 'Avg wait', 'unit' => '', 'c' => $chart(fn ($p) => $p->wait_avg_ms, 'rgb(245 158 11)', 'rgb(245 158 11 / 0.12)'), 'fmt' => $fmtMs],
    ];
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('vigilance.metrics') }}" class="text-xs v-link">&larr; all metrics</a>
    </div>

    <div class="flex flex-wrap items-center gap-2.5">
        <span class="v-pill is-neutral">{{ $type }}</span>
        <h1 class="font-mono text-base font-semibold v-strong">{{ $scope }}</h1>
    </div>

    @if ($points->isEmpty())
        <div class="v-empty">
            <p class="v-empty__title">No snapshots yet for this {{ $type }}.</p>
            <p>Schedule <code class="v-code">vigilance:snapshot</code> to collect history.</p>
        </div>
    @else
        <div class="grid gap-4">
            @foreach ($charts as $card)
                <div class="v-card v-card--pad">
                    <div class="mb-2 flex items-baseline justify-between">
                        <h2 class="v-card__title">{{ $card['title'] }}</h2>
                        @if ($card['c'])
                            <span class="text-xs v-muted v-num">peak {{ $card['fmt']($card['c']['max']) }}</span>
                        @endif
                    </div>
                    @if ($card['c'])
                        <svg viewBox="0 0 {{ $card['c']['w'] }} {{ $card['c']['h'] }}" preserveAspectRatio="none" class="h-20 w-full" role="img" aria-label="{{ $card['title'] }} over time, peak {{ $card['fmt']($card['c']['max']) }}">
                            <path d="{{ $card['c']['area'] }}" fill="{{ $card['c']['fill'] }}" stroke="none" />
                            <path d="{{ $card['c']['line'] }}" fill="none" stroke="{{ $card['c']['stroke'] }}" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                        </svg>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
