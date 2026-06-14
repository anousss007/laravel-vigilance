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
    $fmtMem = fn (?int $b) => $b === null ? '—' : number_format($b / 1048576, 1).'MB';
@endphp

<div wire:poll.visible.5s class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Workload</h1>
            <p class="v-page-sub">Per-queue depth, throughput and latency.</p>
        </div>
        <span class="text-xs v-muted v-num">{{ count($queues) }} queues</span>
    </div>

    <div class="v-card v-card--pad flex flex-wrap items-center gap-4 text-xs">
        <span class="v-stat__label">System load</span>
        @if ($load !== null)
            <span><span class="font-semibold v-strong v-num">{{ $load[1] }}</span> <span class="v-muted">1m</span></span>
            <span><span class="font-semibold v-strong v-num">{{ $load[5] }}</span> <span class="v-muted">5m</span></span>
            <span><span class="font-semibold v-strong v-num">{{ $load[15] }}</span> <span class="v-muted">15m</span></span>
        @else
            <span class="v-muted">n/a on this platform (sys_getloadavg unavailable)</span>
        @endif
    </div>

    <p class="text-xs v-muted">Live queue depth is only available for the <code class="v-code">database</code> and <code class="v-code">redis</code> drivers; other drivers show “n/a”.</p>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($queues as $queue)
            @php
                $series = collect($queue['series'])->map(fn ($p) => (int) ($p->throughput ?? 0))->values();
                $maxPt = max(1, $series->max() ?? 1);
                $sw = 240; $sh = 40; $n = max(1, $series->count());
                $stp = $n > 1 ? $sw / ($n - 1) : $sw;
                $path = '';
                foreach ($series as $i => $v) {
                    $x = round($i * $stp, 2);
                    $y = round($sh - (($v / $maxPt) * ($sh - 4)) - 2, 2);
                    $path .= ($i === 0 ? 'M' : 'L').$x.' '.$y.' ';
                }
            @endphp
            <div class="v-card v-card--pad">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="truncate font-semibold font-mono v-strong">{{ $queue['queue'] }}</h2>
                    <span class="text-[10px] font-mono v-faint">{{ $queue['connection_name'] ?: 'no connection' }}</span>
                </div>

                <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div>
                        <div class="v-stat__label">Depth</div>
                        <div class="font-semibold v-strong v-num">{{ $queue['depth'] === null ? 'n/a' : $queue['depth'] }}</div>
                    </div>
                    <div>
                        <div class="v-stat__label">Workers</div>
                        <div class="font-semibold v-strong v-num">{{ $queue['workers'] }}</div>
                    </div>
                    <div>
                        <div class="v-stat__label">Clear in</div>
                        <div class="font-semibold v-strong v-num">{{ $fmtMs($queue['time_to_clear_ms']) }}</div>
                    </div>
                    <div>
                        <div class="v-stat__label">Proc/1h</div>
                        <div class="font-semibold v-strong v-num">{{ $queue['processed_last_hour'] }}<span class="text-[10px]" style="color: var(--v-danger)">{{ $queue['failed_last_hour'] > 0 ? ' · '.$queue['failed_last_hour'].'✗' : '' }}</span></div>
                    </div>
                    <div>
                        <div class="v-stat__label">Avg run</div>
                        <div class="font-semibold v-strong v-num">{{ $fmtMs($queue['avg_runtime_ms']) }}</div>
                    </div>
                    <div>
                        <div class="v-stat__label">Avg wait</div>
                        <div class="font-semibold v-strong v-num">{{ $fmtMs($queue['avg_wait_ms']) }}</div>
                    </div>
                </div>

                <svg viewBox="0 0 {{ $sw }} {{ $sh }}" preserveAspectRatio="none" class="mt-3 h-10 w-full">
                    @if ($path)
                        <path d="{{ $path }}" fill="none" stroke="var(--v-info)" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                    @else
                        <line x1="0" y1="{{ $sh - 2 }}" x2="{{ $sw }}" y2="{{ $sh - 2 }}" stroke="rgb(113 113 122 / 0.4)" stroke-dasharray="3 3" />
                    @endif
                </svg>
            </div>
        @empty
            <div class="md:col-span-2 xl:col-span-3">
                <div class="v-empty">
                    <p class="v-empty__title">No queue activity</p>
                    <p>No queue activity in the last 24 hours.</p>
                </div>
            </div>
        @endforelse
    </div>

    @if (count($jobClasses))
        <div class="v-card overflow-hidden">
            <div class="v-card__header">
                <h2 class="v-card__title">By job class</h2>
                <span class="text-[10px] uppercase tracking-wide v-faint">last 24 hours</span>
            </div>
            <div class="overflow-x-auto">
                <table class="v-table v-table--hover">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th class="text-right">Runs</th>
                            <th class="text-right">Failed</th>
                            <th class="text-right">Fail %</th>
                            <th class="text-right">Avg</th>
                            <th class="text-right">Max</th>
                            <th class="text-right">Avg mem</th>
                            <th class="text-right">Avg CPU</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($jobClasses as $jc)
                            <tr>
                                <td class="font-medium font-mono v-strong" title="{{ $jc['name'] }}">{{ class_basename($jc['name']) }}</td>
                                <td class="text-right v-num">{{ $jc['runs'] }}</td>
                                <td class="text-right v-num" @if ($jc['failed'] > 0) style="color: var(--v-danger)" @endif>{{ $jc['failed'] }}</td>
                                <td class="text-right v-num">{{ $jc['fail_rate'] }}%</td>
                                <td class="text-right v-num font-mono">{{ $fmtMs($jc['avg_ms']) }}</td>
                                <td class="text-right v-num font-mono v-muted">{{ $fmtMs($jc['max_ms']) }}</td>
                                <td class="text-right v-num font-mono v-muted">{{ $fmtMem($jc['avg_memory']) }}</td>
                                <td class="text-right v-num font-mono v-muted">{{ $jc['avg_cpu'] === null ? '—' : $fmtMs($jc['avg_cpu']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
