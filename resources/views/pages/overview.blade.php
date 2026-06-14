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
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Overview</h1>
            <p class="v-page-sub">Queue, job and scheduler health at a glance.</p>
        </div>
        <span class="v-pill is-success"><span class="v-dot"></span>live · last 24h</span>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            ['Total runs', $counts['total'], null],
            ['Succeeded', $counts['succeeded'], 'var(--v-success)'],
            ['Failed', $counts['failed'], 'var(--v-danger)'],
            ['Success rate', $counts['success_rate'].'%', 'var(--v-accent-strong)'],
        ] as [$label, $value, $tone])
            <div class="v-stat">
                <div class="v-stat__label">{{ $label }}</div>
                <div class="v-stat__value" @if ($tone) style="color: {{ $tone }}" @endif>{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- Throughput sparkline --}}
    <div class="v-card">
        <div class="v-card__header">
            <h2 class="v-card__title">Throughput</h2>
            <span class="text-xs v-muted v-num">{{ $totalThroughput }} runs · {{ $totalFailed }} failed · 60 min</span>
        </div>
        <div class="v-card--pad">
            <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" class="h-16 w-full">
                <path d="{{ $area }}" fill="rgb(16 185 129 / 0.12)" stroke="none" />
                <path d="{{ $line }}" fill="none" stroke="rgb(16 185 129)" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                @foreach ($deployMarkers as $marker)
                    <line x1="{{ $marker['x'] }}" y1="0" x2="{{ $marker['x'] }}" y2="{{ $h }}" stroke="rgb(56 189 248 / 0.7)" stroke-width="1" stroke-dasharray="3 2" vector-effect="non-scaling-stroke" />
                @endforeach
            </svg>
            @if ($deployMarkers->isNotEmpty())
                <p class="mt-2 text-[11px]" style="color: var(--v-info)"><span aria-hidden="true">┊</span> deploy markers on the timeline</p>
            @endif
        </div>
    </div>

    {{-- Recent deployments --}}
    @if (count($deployments) > 0)
        <div class="v-card">
            <div class="v-card__header">
                <h2 class="v-card__title">Recent deployments</h2>
            </div>
            <ul>
                @foreach ($deployments as $deployment)
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-xs" style="border-top: 1px solid var(--v-border);">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="v-pill is-info font-mono">{{ $deployment->label() }}</span>
                            @if ($deployment->environment)
                                <span class="v-faint">{{ $deployment->environment }}</span>
                            @endif
                            @if ($deployment->notes)
                                <span class="truncate v-faint">{{ $deployment->notes }}</span>
                            @endif
                        </div>
                        <span class="shrink-0 v-faint">{{ $deployment->deployed_at->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Top failing groups --}}
        <div class="v-card">
            <div class="v-card__header">
                <h2 class="v-card__title">Top failure groups</h2>
                <a href="{{ route('vigilance.failures') }}" class="text-xs v-link">view all</a>
            </div>
            <ul>
                @forelse ($topFailing as $group)
                    <li class="px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                        <a href="{{ route('vigilance.runs', ['group' => $group->id]) }}" class="block transition-opacity hover:opacity-80">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-[13px] font-medium v-strong">{{ $group->name ?: $group->exception_class }}</span>
                                <span class="v-pill is-danger v-num shrink-0">{{ $group->occurrences }}×</span>
                            </div>
                            <div class="mt-0.5 truncate text-xs v-muted">{{ $group->message }}</div>
                        </a>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-xs v-muted">No open failure groups.</li>
                @endforelse
            </ul>
        </div>

        {{-- Recent failures --}}
        <div class="v-card">
            <div class="v-card__header">
                <h2 class="v-card__title">Recent failures</h2>
                <a href="{{ route('vigilance.runs', ['status' => 'failed']) }}" class="text-xs v-link">view all</a>
            </div>
            <ul>
                @forelse ($recentFailures as $run)
                    <li class="px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                        <a href="{{ route('vigilance.runs.show', $run->id) }}" class="block transition-opacity hover:opacity-80">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-[13px] font-medium v-strong">{{ $run->name }}</span>
                                <span class="shrink-0 text-xs v-faint">{{ optional($run->finished_at)->diffForHumans() }}</span>
                            </div>
                            <div class="mt-0.5 truncate text-xs font-mono" style="color: var(--v-danger)">{{ $run->exception_class }}: {{ $run->exception_message }}</div>
                        </a>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-xs v-muted">No recent failures.</li>
                @endforelse
            </ul>
        </div>

        {{-- Slowest runs --}}
        <div class="v-card">
            <div class="v-card__header">
                <h2 class="v-card__title">Slowest runs</h2>
            </div>
            <ul>
                @forelse ($slowest as $run)
                    <li class="flex items-center justify-between px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                        <a href="{{ route('vigilance.runs.show', $run->id) }}" class="truncate text-[13px] font-medium v-strong hover:underline">{{ $run->name }}</a>
                        <span class="v-pill is-warn v-num shrink-0">{{ $fmtMs($run->duration_ms) }}</span>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-xs v-muted">No measured runs yet.</li>
                @endforelse
            </ul>
        </div>

        {{-- Workload summary --}}
        <div class="v-card">
            <div class="v-card__header">
                <h2 class="v-card__title">Workload</h2>
                <a href="{{ route('vigilance.workload') }}" class="text-xs v-link">details</a>
            </div>
            <div class="grid grid-cols-2 gap-4 p-4">
                <div>
                    <div class="v-stat__label">Active queues</div>
                    <div class="mt-1.5 text-2xl font-semibold v-strong v-num">{{ $queueCount }}</div>
                </div>
                <div>
                    <div class="v-stat__label">Total depth</div>
                    <div class="mt-1.5 text-2xl font-semibold v-strong v-num">{{ $totalDepth }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
