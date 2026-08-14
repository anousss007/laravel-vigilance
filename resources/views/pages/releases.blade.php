@php
    use Carbon\CarbonImmutable;

    $verdictPill = [
        'regressed' => 'is-danger',
        'degraded' => 'is-warn',
        'healthy' => 'is-success',
        'no-data' => 'is-neutral',
    ];

    $fmtPct = function (?float $v): string {
        if ($v === null) {
            return '—';
        }
        return ($v >= 0 ? '+' : '').number_format($v, 1).'%';
    };
@endphp

<div @vigilancePoll('30s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Releases</h1>
            <p class="v-page-sub">Deploy markers with a health verdict — error rate &amp; latency for the {{ $windowMinutes }}m after each deploy vs. the {{ $windowMinutes }}m before.</p>
        </div>
    </div>

    <x-vigilance::ui.card class="overflow-hidden">
        <x-vigilance::ui.table>
            <thead>
                <tr>
                    <th scope="col">Release</th>
                    <th scope="col">Deployed</th>
                    <th scope="col">Health</th>
                    <th scope="col" class="text-right">Error rate</th>
                    <th scope="col" class="text-right">Latency (avg)</th>
                    <th scope="col" class="text-right">Throughput</th>
                    <th scope="col" class="text-right">Reqs (after)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($releases as $r)
                    <tr wire:key="release-{{ $r->deploymentId }}">
                        <td>
                            <span class="font-medium v-strong">{{ $r->version ?: '#'.$r->deploymentId }}</span>
                            @if ($r->commit)
                                <span class="ml-1 font-mono text-[11px] v-faint">{{ $r->commit }}</span>
                            @endif
                        </td>
                        <td class="v-muted" title="{{ CarbonImmutable::createFromTimestamp($r->deployedAt)->toDateTimeString() }}">
                            {{ CarbonImmutable::createFromTimestamp($r->deployedAt)->diffForHumans() }}
                        </td>
                        <td>
                            <span @class(['v-pill', $verdictPill[$r->verdict] ?? 'is-neutral'])><span class="v-dot"></span>{{ $r->verdict }}</span>
                        </td>
                        <td class="text-right v-num">
                            @if ($r->hasData())
                                <span class="v-muted">{{ number_format($r->errorRateBefore, 1) }}% → </span>
                                <span @class(['font-medium', 'v-strong' => $r->errorRateDelta() <= 0])
                                      @if ($r->errorRateDelta() > 0) style="color: var(--v-danger)" @endif>{{ number_format($r->errorRateAfter, 1) }}%</span>
                            @else
                                <span class="v-faint">—</span>
                            @endif
                        </td>
                        <td class="text-right v-num">
                            @if ($r->latencyBefore !== null && $r->latencyAfter !== null)
                                <span class="v-muted">{{ $r->latencyBefore }}ms → </span>
                                <span @class(['font-medium'])
                                      @if (($r->latencyDelta() ?? 0) > 0) style="color: var(--v-warn)" @else style="color: var(--v-text-strong)" @endif>{{ $r->latencyAfter }}ms</span>
                                <span class="text-[11px] v-faint">({{ $fmtPct($r->latencyDeltaPercent()) }})</span>
                            @else
                                <span class="v-faint">—</span>
                            @endif
                        </td>
                        <td class="text-right v-num v-muted">{{ $r->hasData() ? $fmtPct($r->throughputDelta) : '—' }}</td>
                        <td class="text-right v-num v-faint">{{ number_format($r->requestsAfter) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">
                        <x-vigilance::ui.empty>
<x-vigilance::ui.empty-title>No deployments recorded.</x-vigilance::ui.empty-title>
<x-vigilance::ui.empty-description><p>Record one from your deploy script: <code class="font-mono">php artisan vigilance:deploy --release=v1.4.0</code></p></x-vigilance::ui.empty-description>
</x-vigilance::ui.empty>
                    </td></tr>
                @endforelse
            </tbody>
        </x-vigilance::ui.table>
    </x-vigilance::ui.card>
</div>
