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
    $fmtExpiry = function (?int $ts): string {
        if ($ts === null) {
            return 'indefinitely';
        }
        $mins = (int) ceil(($ts - time()) / 60);
        return $mins > 0 ? "for ~{$mins} min" : 'briefly';
    };
    // Queues shown as cards below (so a paused-but-idle queue can still be listed).
    $listed = collect($queues)->map(fn ($q) => ($q['connection_name'] ?? '').'|'.$q['queue'])->all();
@endphp

<div @vigilancePoll('5s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Workload</h1>
            <p class="v-page-sub">Per-queue depth, throughput and latency.</p>
        </div>
        <span class="text-xs v-muted v-num">{{ count($queues) }} queues</span>
    </div>

    <x-vigilance::ui.card class="flex flex-wrap items-center gap-4 text-xs">
        <span class="v-stat__label">System load</span>
        @if ($load !== null)
            <span><span class="font-semibold v-strong v-num">{{ $load[1] }}</span> <span class="v-muted">1m</span></span>
            <span><span class="font-semibold v-strong v-num">{{ $load[5] }}</span> <span class="v-muted">5m</span></span>
            <span><span class="font-semibold v-strong v-num">{{ $load[15] }}</span> <span class="v-muted">15m</span></span>
        @else
            <span class="v-muted">n/a on this platform (sys_getloadavg unavailable)</span>
        @endif
    </x-vigilance::ui.card>

    @php $orphanPaused = collect($paused)->reject(fn ($e, $key) => in_array($key, $listed, true)); @endphp
    @if ($orphanPaused->isNotEmpty())
        <x-vigilance::ui.card class="space-y-2">
            <span class="v-stat__label">Paused queues (no recent activity)</span>
            <div class="flex flex-wrap gap-2">
                @foreach ($orphanPaused as $key => $expiresAt)
                    @php [$conn, $q] = array_pad(explode('|', $key, 2), 2, ''); @endphp
                    <x-vigilance::ui.badge tone="warning" class="inline-flex items-center gap-2">
                        <span class="v-dot"></span>
                        <span class="font-mono">{{ $q }}</span>
                        <span class="v-faint">· {{ $conn }} · {{ $fmtExpiry($expiresAt) }}</span>
                        <x-vigilance::ui.button variant="outline" size="sm" wire:click="resumeQueue(@js($conn), @js($q))">Resume</x-vigilance::ui.button>
                    </x-vigilance::ui.badge>
                @endforeach
            </div>
        </x-vigilance::ui.card>
    @endif

    <p class="text-xs v-muted">Live queue depth is only available for the <code class="v-code">database</code> and <code class="v-code">redis</code> drivers; other drivers show “n/a”.</p>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($queues as $queue)
            @php
                $conn = (string) ($queue['connection_name'] ?? '');
                $qKey = $conn.'|'.$queue['queue'];
                $isPaused = array_key_exists($qKey, $paused);
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
            <x-vigilance::ui.card>
                <div class="flex items-baseline justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <h2 class="truncate font-semibold font-mono v-strong">{{ $queue['queue'] }}</h2>
                        @if ($isPaused)
                            <x-vigilance::ui.badge tone="warning" class="uppercase tracking-wide text-[10px]"><span class="v-dot"></span>paused</x-vigilance::ui.badge>
                        @endif
                    </div>
                    <span class="text-[10px] font-mono v-faint">{{ $queue['connection_name'] ?: 'no connection' }}</span>
                </div>

                <div class="mt-3 grid grid-cols-1 gap-2 text-center sm:grid-cols-3">
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

                @if ($conn !== '')
                    <div class="mt-3 flex flex-wrap items-center gap-2 border-t pt-3">
                        @if ($isPaused)
                            <x-vigilance::ui.button size="sm" wire:click="resumeQueue(@js($conn), @js($queue['queue']))">Resume</x-vigilance::ui.button>
                        @else
                            <x-vigilance::ui.button variant="outline" size="sm" wire:click="pauseQueue(@js($conn), @js($queue['queue']))">Pause</x-vigilance::ui.button>
                            <x-vigilance::ui.button variant="outline" size="sm" wire:click="pauseQueue(@js($conn), @js($queue['queue']), 15)" title="Pause for 15 minutes">15m</x-vigilance::ui.button>
                            <x-vigilance::ui.button variant="outline" size="sm" wire:click="pauseQueue(@js($conn), @js($queue['queue']), 60)" title="Pause for 1 hour">1h</x-vigilance::ui.button>
                        @endif
                        @if ($controlEnabled)
                            <x-vigilance::ui.button variant="destructive" size="sm" class="ml-auto" wire:click="clearQueue(@js($conn), @js($queue['queue']))" wire:confirm="Delete ALL pending jobs on [{{ $queue['queue'] }}]? This cannot be undone.">Clear</x-vigilance::ui.button>
                        @endif
                    </div>
                @endif
            </x-vigilance::ui.card>
        @empty
            <div class="md:col-span-2 xl:col-span-3">
                <x-vigilance::ui.empty>
    <x-vigilance::ui.empty-title>No queue activity</x-vigilance::ui.empty-title>
    <x-vigilance::ui.empty-description><p>No queue activity in the last 24 hours.</p></x-vigilance::ui.empty-description>
</x-vigilance::ui.empty>
            </div>
        @endforelse
    </div>

    @if (count($jobClasses))
        <x-vigilance::ui.card variant="sectioned" class="overflow-hidden">
            <x-vigilance::ui.card-header>
                <x-vigilance::ui.card-title>By job class</x-vigilance::ui.card-title>
                <span class="text-[10px] uppercase tracking-wide v-faint">last 24 hours</span>
            </x-vigilance::ui.card-header>
            <x-vigilance::ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Job</th>
                            <th scope="col" class="text-right">Runs</th>
                            <th scope="col" class="text-right">Failed</th>
                            <th scope="col" class="text-right">Fail %</th>
                            <th scope="col" class="text-right">Avg</th>
                            <th scope="col" class="text-right">Max</th>
                            <th scope="col" class="text-right">Avg mem</th>
                            <th scope="col" class="text-right">Avg CPU</th>
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
                </x-vigilance::ui.table>
        </x-vigilance::ui.card>
    @endif
</div>
