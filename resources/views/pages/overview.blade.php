@php
    $fmtMs = function (?int $ms): string {
        if ($ms === null) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms.'ms';
        }
        return number_format($ms / 1000, 2).'s';
    };

    // Build an inline SVG sparkline path from the throughput series.
    $points = collect($throughput);
    $max = max(1, $points->max('count') ?? 1);
    $w = 600;
    $h = 60;
    $count = max(1, $points->count());
    $step = $count > 1 ? $w / ($count - 1) : $w;
    $line = '';
    $area = '';
    foreach ($points->values() as $i => $p) {
        $x = round($i * $step, 2);
        $y = round($h - (($p['count'] / $max) * ($h - 6)) - 3, 2);
        $line .= ($i === 0 ? 'M' : 'L').$x.' '.$y.' ';
        $area .= ($i === 0 ? 'M'.$x.' '.$h.' L'.$x.' '.$y.' ' : 'L'.$x.' '.$y.' ');
    }
    $area .= 'L'.round(($count - 1) * $step, 2).' '.$h.' Z';
    $totalThroughput = $points->sum('count');
    $totalFailed = $points->sum('failed');

    // Deploy markers: map deploys within the charted window to an x position.
    $now = \Carbon\CarbonImmutable::now();
    $deployMarkers = collect($deployments)
        ->map(function ($d) use ($now, $count, $step) {
            $minutesAgo = (int) round(abs($now->diffInMinutes($d->deployed_at)));
            if ($minutesAgo > ($count - 1)) {
                return null;
            }
            $index = ($count - 1) - $minutesAgo;

            return ['x' => round($index * $step, 2), 'label' => $d->label()];
        })
        ->filter()
        ->values();
@endphp

<div wire:poll.visible.5s class="space-y-6">
    <div class="flex items-baseline justify-between">
        <h1 class="text-base font-semibold">Overview</h1>
        <span class="text-xs text-zinc-600 dark:text-zinc-400">last 24 hours</span>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['Total', $counts['total'], 'text-zinc-900 dark:text-zinc-100'],
            ['Succeeded', $counts['succeeded'], 'text-emerald-700 dark:text-emerald-300'],
            ['Failed', $counts['failed'], 'text-red-700 dark:text-red-300'],
            ['Success rate', $counts['success_rate'].'%', 'text-blue-700 dark:text-blue-300'],
        ] as [$label, $value, $tone])
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-xs text-zinc-600 dark:text-zinc-400">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold {{ $tone }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- Throughput sparkline --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold">Throughput</h2>
            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $totalThroughput }} runs / {{ $totalFailed }} failed (60 min)</span>
        </div>
        <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" class="h-16 w-full">
            <path d="{{ $area }}" fill="rgb(16 185 129 / 0.12)" stroke="none" />
            <path d="{{ $line }}" fill="none" stroke="rgb(16 185 129)" stroke-width="1.5" vector-effect="non-scaling-stroke" />
            @foreach ($deployMarkers as $marker)
                <line x1="{{ $marker['x'] }}" y1="0" x2="{{ $marker['x'] }}" y2="{{ $h }}" stroke="rgb(59 130 246 / 0.7)" stroke-width="1" stroke-dasharray="3 2" vector-effect="non-scaling-stroke" />
            @endforeach
        </svg>
        @if ($deployMarkers->isNotEmpty())
            <p class="mt-1 text-[11px] text-blue-700 dark:text-blue-300"><span aria-hidden="true">┊</span> deploy markers shown on the timeline</p>
        @endif
    </div>

    {{-- Recent deployments --}}
    @if (count($deployments) > 0)
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="text-sm font-semibold">Recent deployments</h2>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($deployments as $deployment)
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-xs">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="rounded bg-blue-500/10 px-1.5 py-0.5 font-mono text-blue-700 dark:text-blue-300">{{ $deployment->label() }}</span>
                            @if ($deployment->environment)
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $deployment->environment }}</span>
                            @endif
                            @if ($deployment->notes)
                                <span class="truncate text-zinc-500 dark:text-zinc-400">{{ $deployment->notes }}</span>
                            @endif
                        </div>
                        <span class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ $deployment->deployed_at->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Top failing groups --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="text-sm font-semibold">Top failure groups</h2>
                <a href="{{ route('vigilance.failures') }}" class="text-xs text-emerald-700 hover:underline dark:text-emerald-300">view all</a>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($topFailing as $group)
                    <li class="px-4 py-2.5">
                        <a href="{{ route('vigilance.runs', ['group' => $group->id]) }}" class="block hover:opacity-80">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate font-medium">{{ $group->name ?: $group->exception_class }}</span>
                                <span class="shrink-0 rounded bg-red-500/10 px-1.5 py-0.5 text-xs text-red-700 dark:text-red-300">{{ $group->occurrences }}×</span>
                            </div>
                            <div class="mt-0.5 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $group->message }}</div>
                        </a>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-xs text-zinc-600 dark:text-zinc-400">No open failure groups.</li>
                @endforelse
            </ul>
        </div>

        {{-- Recent failures --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="text-sm font-semibold">Recent failures</h2>
                <a href="{{ route('vigilance.runs', ['status' => 'failed']) }}" class="text-xs text-emerald-700 hover:underline dark:text-emerald-300">view all</a>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($recentFailures as $run)
                    <li class="px-4 py-2.5">
                        <a href="{{ route('vigilance.runs.show', $run->id) }}" class="block hover:opacity-80">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate font-medium">{{ $run->name }}</span>
                                <span class="shrink-0 text-xs text-zinc-600 dark:text-zinc-400">{{ optional($run->finished_at)->diffForHumans() }}</span>
                            </div>
                            <div class="mt-0.5 truncate text-xs text-red-700 dark:text-red-300">{{ $run->exception_class }}: {{ $run->exception_message }}</div>
                        </a>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-xs text-zinc-600 dark:text-zinc-400">No recent failures. 🎉</li>
                @endforelse
            </ul>
        </div>

        {{-- Slowest runs --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="text-sm font-semibold">Slowest runs</h2>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($slowest as $run)
                    <li class="flex items-center justify-between px-4 py-2.5">
                        <a href="{{ route('vigilance.runs.show', $run->id) }}" class="truncate font-medium hover:underline">{{ $run->name }}</a>
                        <span class="shrink-0 rounded bg-amber-500/10 px-1.5 py-0.5 text-xs text-amber-700 dark:text-amber-300">{{ $fmtMs($run->duration_ms) }}</span>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-xs text-zinc-600 dark:text-zinc-400">No measured runs yet.</li>
                @endforelse
            </ul>
        </div>

        {{-- Workload summary --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="text-sm font-semibold">Workload</h2>
                <a href="{{ route('vigilance.workload') }}" class="text-xs text-emerald-700 hover:underline dark:text-emerald-300">details</a>
            </div>
            <div class="grid grid-cols-2 gap-3 p-4">
                <div>
                    <div class="text-xs text-zinc-600 dark:text-zinc-400">Active queues</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $queueCount }}</div>
                </div>
                <div>
                    <div class="text-xs text-zinc-600 dark:text-zinc-400">Total depth</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $totalDepth }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
