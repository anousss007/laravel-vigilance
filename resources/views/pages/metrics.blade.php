@php
    $fmtMs = fn (?int $ms) => $ms === null ? '—' : ($ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s');
@endphp

<div class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Metrics</h1>
            <p class="v-page-sub">Throughput, failure rate and runtime per job class &amp; queue · last 24h.</p>
        </div>
    </div>

    {{-- Jobs --}}
    <div class="v-card overflow-hidden">
        <div class="v-card__header">
            <h2 class="v-card__title">By job class</h2>
        </div>
        <div class="overflow-x-auto" tabindex="0">
            <table class="v-table v-table--hover">
                <caption class="sr-only">Job classes by throughput, failure rate and runtime</caption>
                <thead>
                    <tr>
                        <th scope="col">Job</th>
                        <th scope="col" class="text-right">Runs</th>
                        <th scope="col" class="text-right">Fail %</th>
                        <th scope="col" class="text-right">Avg</th>
                        <th scope="col" class="text-right">Max</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobs as $job)
                        <tr>
                            <td>
                                <a href="{{ route('vigilance.metrics.show', ['type' => 'job', 'scope' => $job['name']]) }}" class="font-mono font-medium v-strong hover:underline">{{ $job['name'] }}</a>
                            </td>
                            <td class="text-right v-num">{{ number_format($job['runs']) }}</td>
                            <td class="text-right v-num" @if ($job['fail_rate'] > 0) style="color: var(--v-danger)" @else style="color: var(--v-faint)" @endif>{{ $job['fail_rate'] }}%</td>
                            <td class="text-right v-num font-mono">{{ $fmtMs($job['avg_ms']) }}</td>
                            <td class="text-right v-num font-mono v-muted">{{ $fmtMs($job['max_ms']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center v-muted">No job metrics yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Queues --}}
    <div class="v-card overflow-hidden">
        <div class="v-card__header">
            <h2 class="v-card__title">By queue</h2>
        </div>
        <div class="overflow-x-auto" tabindex="0">
            <table class="v-table v-table--hover">
                <caption class="sr-only">Queues by throughput and runtime</caption>
                <thead>
                    <tr>
                        <th scope="col">Queue</th>
                        <th scope="col" class="text-right">Processed/h</th>
                        <th scope="col" class="text-right">Avg runtime</th>
                        <th scope="col" class="text-right">Avg wait</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($queues as $queue)
                        <tr>
                            <td>
                                <a href="{{ route('vigilance.metrics.show', ['type' => 'queue', 'scope' => $queue['queue']]) }}" class="font-mono font-medium v-strong hover:underline">{{ $queue['queue'] }}</a>
                            </td>
                            <td class="text-right v-num">{{ number_format($queue['processed_last_hour']) }}</td>
                            <td class="text-right v-num font-mono">{{ $fmtMs($queue['avg_runtime_ms']) }}</td>
                            <td class="text-right v-num font-mono v-muted">{{ $fmtMs($queue['avg_wait_ms']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center v-muted">No active queues.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
