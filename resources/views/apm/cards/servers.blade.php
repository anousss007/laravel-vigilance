@php
    $fmtMb = fn (int $mb) => $mb <= 0 ? '—' : ($mb >= 1024 ? number_format($mb / 1024, 1).' GB' : $mb.' MB');
    $ago = fn (?int $ts) => $ts ? \Carbon\CarbonImmutable::createFromTimestamp($ts)->diffForHumans() : '—';
    $spark = function (array $series, string $stroke) {
        $vals = array_values(array_filter($series, fn ($v) => $v !== null));
        if ($vals === []) {
            return null;
        }
        $max = max(1, max($vals));
        $w = 280;
        $h = 40;
        $count = max(1, count($series));
        $step = $count > 1 ? $w / ($count - 1) : $w;
        $line = '';
        $started = false;
        foreach (array_values($series) as $i => $v) {
            if ($v === null) {
                continue;
            }
            $x = round($i * $step, 2);
            $y = round($h - ($v / $max) * ($h - 6) - 3, 2);
            $line .= ($started ? 'L' : 'M').$x.' '.$y.' ';
            $started = true;
        }

        return ['line' => $line, 'w' => $w, 'h' => $h, 'stroke' => $stroke];
    };
@endphp

<div>
    <h2 class="v-card__title mb-3">Servers</h2>
    @if (count($servers) === 0)
        <div class="v-empty">
            <p class="v-empty__title">No server is reporting yet</p>
            <p>Run <code class="v-code">php artisan vigilance:check</code> on each app server.</p>
        </div>
    @else
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($servers as $server)
                @php
                    $memPct = $server['memory_total'] > 0 ? min(100, round($server['memory_used'] / $server['memory_total'] * 100)) : 0;
                    $cpuSpark = $spark($server['cpu_series'], 'rgb(59 130 246)');
                @endphp
                <div class="v-card v-card--pad">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="inline-block h-2 w-2 rounded-full" aria-hidden="true" style="background: {{ $server['online'] ? 'var(--v-success)' : 'var(--v-faint)' }};"></span>
                            <span class="font-semibold v-strong">{{ $server['name'] }}</span>
                            <span class="v-pill {{ $server['online'] ? 'is-success' : 'is-neutral' }}">{{ $server['online'] ? 'online' : 'stale' }}</span>
                        </div>
                        <span class="text-xs v-muted">{{ $ago($server['updated_at']) }}</span>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-xs v-muted">CPU</span>
                                <span class="text-sm font-semibold v-strong v-num">{{ $server['cpu'] }}%</span>
                            </div>
                            @if ($cpuSpark)
                                <svg viewBox="0 0 {{ $cpuSpark['w'] }} {{ $cpuSpark['h'] }}" preserveAspectRatio="none" class="mt-1 h-8 w-full" aria-hidden="true" focusable="false">
                                    <path d="{{ $cpuSpark['line'] }}" fill="none" stroke="{{ $cpuSpark['stroke'] }}" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                                </svg>
                            @endif
                        </div>
                        <div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-xs v-muted">Memory</span>
                                <span class="text-sm font-semibold v-strong v-num">{{ $fmtMb($server['memory_used']) }} <span class="text-xs font-normal v-faint">/ {{ $fmtMb($server['memory_total']) }}</span></span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full" role="img" aria-label="Memory {{ $memPct }}% used" style="background: var(--v-surface-2);">
                                <div class="h-full rounded-full" style="width: {{ $memPct }}%; background: var(--v-accent);"></div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
