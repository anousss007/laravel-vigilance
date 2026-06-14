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
    <h2 class="mb-3 text-sm font-semibold">Servers</h2>
    @if (count($servers) === 0)
        <div class="rounded-lg border border-dashed border-zinc-300 bg-white px-4 py-8 text-center text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
            No server is reporting yet. Run <code class="rounded bg-zinc-100 px-1 py-0.5 dark:bg-zinc-800">php artisan vigilance:check</code> on each app server.
        </div>
    @else
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($servers as $server)
                @php
                    $memPct = $server['memory_total'] > 0 ? min(100, round($server['memory_used'] / $server['memory_total'] * 100)) : 0;
                    $cpuSpark = $spark($server['cpu_series'], 'rgb(59 130 246)');
                @endphp
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span @class(['inline-block h-2 w-2 rounded-full', 'bg-emerald-500' => $server['online'], 'bg-zinc-400 dark:bg-zinc-600' => ! $server['online']]) aria-hidden="true"></span>
                            <span class="font-semibold">{{ $server['name'] }}</span>
                            <span class="rounded px-1.5 py-0.5 text-[10px] {{ $server['online'] ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' }}">{{ $server['online'] ? 'online' : 'stale' }}</span>
                        </div>
                        <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $ago($server['updated_at']) }}</span>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-xs text-zinc-600 dark:text-zinc-400">CPU</span>
                                <span class="text-sm font-semibold">{{ $server['cpu'] }}%</span>
                            </div>
                            @if ($cpuSpark)
                                <svg viewBox="0 0 {{ $cpuSpark['w'] }} {{ $cpuSpark['h'] }}" preserveAspectRatio="none" class="mt-1 h-8 w-full" aria-hidden="true" focusable="false">
                                    <path d="{{ $cpuSpark['line'] }}" fill="none" stroke="{{ $cpuSpark['stroke'] }}" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                                </svg>
                            @endif
                        </div>
                        <div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-xs text-zinc-600 dark:text-zinc-400">Memory</span>
                                <span class="text-sm font-semibold">{{ $fmtMb($server['memory_used']) }} <span class="text-xs font-normal text-zinc-500">/ {{ $fmtMb($server['memory_total']) }}</span></span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800" role="img" aria-label="Memory {{ $memPct }}% used">
                                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $memPct }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
