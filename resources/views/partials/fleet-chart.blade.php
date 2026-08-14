@props(['series', 'ranges', 'labels', 'current'])

@php
    // A step chart, not a line: worker counts change in whole processes at
    // discrete moments, and interpolating between them would draw a scale event
    // that never happened.
    $visible = collect($series)->reject(fn ($s) => $s['hidden'])->values();
    $ceiling = max(1, $visible->max('max') ?? 1);
    // Colour follows the series, not its rank among the *visible* ones: both the
    // line and its legend swatch index into $series, so hiding one pool never
    // repaints the others. The tail past the palette arrives pre-folded into a
    // single "Other" series (see Workers::foldTail) and wears a neutral, so no
    // two pools can ever share a hue.
    $palette = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];
    $colour = fn (int $i, array $s) => $s['other'] ?? false ? 'var(--v-faint)' : $palette[$i % count($palette)];

    $path = function (array $points) use ($ceiling) {
        $width = 600;
        $height = 120;
        $count = max(1, count($points));
        $step = $count > 1 ? $width / ($count - 1) : $width;
        $d = '';
        $prevY = null;

        foreach (array_values($points) as $i => $value) {
            if ($value === null) {
                continue;
            }

            $x = round($i * $step, 2);
            $y = round($height - ($value / $ceiling) * ($height - 8) - 4, 2);

            if ($prevY === null) {
                $d .= 'M'.$x.' '.$y.' ';
            } else {
                // Hold the previous level, then step to the new one.
                $d .= 'L'.$x.' '.$prevY.' L'.$x.' '.$y.' ';
            }

            $prevY = $y;
        }

        return trim($d);
    };
@endphp

<x-vigilance::ui.card variant="sectioned">
    <x-vigilance::ui.card-header class="flex-wrap gap-3">
        <div>
            <x-vigilance::ui.card-title>Fleet size over time</x-vigilance::ui.card-title>
            <x-vigilance::ui.card-description>
                Every scale event, including the stretches at zero.
            </x-vigilance::ui.card-description>
        </div>
        <x-vigilance::range-picker :ranges="$ranges" :labels="$labels" :current="$current" />
    </x-vigilance::ui.card-header>

    <x-vigilance::ui.card-content>
        @if (count($series) === 0)
            <x-vigilance::ui.empty>
                <x-vigilance::ui.empty-title>No scaling history yet</x-vigilance::ui.empty-title>
                <x-vigilance::ui.empty-description>
                    Run <span class="font-mono">php artisan vigilance:supervise</span>; the fleet size is
                    sampled every 15 seconds.
                </x-vigilance::ui.empty-description>
            </x-vigilance::ui.empty>
        @else
            <svg viewBox="0 0 600 120" preserveAspectRatio="none" class="h-32 w-full" role="img"
                 aria-label="Worker count over time">
                @foreach ($series as $i => $s)
                    @continue($s['hidden'])
                    <path d="{{ $path($s['points']) }}" fill="none" stroke-width="2"
                          stroke="{{ $colour($i, $s) }}"
                          vector-effect="non-scaling-stroke" />
                @endforeach
            </svg>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                @foreach ($series as $i => $s)
                    <button type="button" wire:click="toggleSeries('{{ addslashes($s['key']) }}')"
                            aria-pressed="{{ $s['hidden'] ? 'false' : 'true' }}"
                            @class(['v-pill', 'opacity-40' => $s['hidden']])>
                        <span class="v-dot" @style([
                            'background: '.$colour($i, $s) => ! $s['hidden'],
                        ])></span>
                        {{ $s['label'] }}
                        <span class="v-faint">peak {{ $s['max'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    </x-vigilance::ui.card-content>
</x-vigilance::ui.card>
