<div wire:poll.visible.10s class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Routes</h1>
            <p class="v-page-sub">Per-route throughput, error rate, Apdex and latency percentiles.</p>
        </div>
    </div>

    <div class="v-card v-card--pad">
        <div class="flex flex-wrap items-center gap-1">
            @foreach (['1h' => 'Last hour', '6h' => 'Last 6h', '24h' => 'Last 24h'] as $key => $label)
                <button type="button" wire:click="setWindow('{{ $key }}')"
                        @class(['v-btn v-btn--sm', 'v-btn--primary' => $window === $key, 'v-btn--ghost' => $window !== $key])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="v-card overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="v-table v-table--hover">
                <thead>
                    <tr>
                        <th scope="col">Route</th>
                        <th scope="col" class="text-right">Reqs</th>
                        <th scope="col" class="text-right">Err %</th>
                        <th scope="col" class="text-right">Apdex</th>
                        <th scope="col" class="text-right">p50</th>
                        <th scope="col" class="text-right">p95</th>
                        <th scope="col" class="text-right">p99</th>
                        <th scope="col" class="text-right">max</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($routes as $r)
                        <tr wire:key="route-{{ $loop->index }}">
                            <td class="max-w-md truncate">
                                <span class="v-pill is-neutral">{{ $r->method }}</span>
                                <span class="ml-1 font-mono v-strong">{{ $r->path }}</span>
                            </td>
                            <td class="text-right v-num v-muted">{{ number_format($r->count) }}</td>
                            <td class="text-right v-num">
                                <span @class([
                                    'v-pill',
                                    'is-neutral' => $r->error_rate == 0,
                                    'is-warn' => $r->error_rate > 0 && $r->error_rate <= 5,
                                    'is-danger' => $r->error_rate > 5,
                                ])>{{ $r->error_rate }}%</span>
                            </td>
                            <td class="text-right v-num">
                                @if ($r->apdex !== null)
                                    <span @class([
                                        'v-pill',
                                        'is-success' => $r->apdex >= 0.94,
                                        'is-warn' => $r->apdex >= 0.8 && $r->apdex < 0.94,
                                        'is-danger' => $r->apdex < 0.8,
                                    ])>{{ number_format($r->apdex, 2) }}</span>
                                @else
                                    <span class="v-faint">—</span>
                                @endif
                            </td>
                            <td class="text-right v-num v-muted">{{ $r->p50 !== null ? $r->p50.'ms' : '—' }}</td>
                            <td class="text-right v-num font-medium v-strong">{{ $r->p95 !== null ? $r->p95.'ms' : '—' }}</td>
                            <td class="text-right v-num v-muted">{{ $r->p99 !== null ? $r->p99.'ms' : '—' }}</td>
                            <td class="text-right v-num v-faint">{{ $r->max }}ms</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">
                            <div class="v-empty">
                                <p class="v-empty__title">No request data yet.</p>
                                <p>Routes appear here as the Requests recorder captures traffic.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
