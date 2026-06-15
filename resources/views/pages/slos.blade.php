@php
    $pill = fn (string $s) => match ($s) {
        'healthy' => 'is-success',
        'at-risk' => 'is-warn',
        'breaching' => 'is-danger',
        default => 'is-neutral',
    };
@endphp

<div wire:poll.visible.30s class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">SLOs</h1>
            <p class="v-page-sub">Service-level objectives, error budget and burn rate.</p>
        </div>
    </div>

    @if ($slos->isEmpty())
        <div class="v-empty">
            <p class="v-empty__title">No SLOs defined.</p>
            <p>Add objectives under <code class="v-code">config/vigilance.php</code> &rarr; <code class="v-code">slos</code> (success-rate or latency).</p>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($slos as $slo)
                @php $status = $slo->status(); @endphp
                <div class="v-card v-card--pad space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="truncate font-semibold v-strong">{{ $slo->name }}</h2>
                            <p class="text-[11px] uppercase tracking-wide v-faint">{{ $slo->sli === 'latency' ? 'Latency · Apdex' : 'Success rate' }} &middot; {{ $slo->windowDays }}d</p>
                        </div>
                        <span @class(['v-pill', $pill($status)])><span class="v-dot"></span>{{ $status }}</span>
                    </div>

                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl font-semibold v-strong v-num">{{ $status === 'no-data' ? '—' : number_format($slo->current, 2).'%' }}</span>
                        <span class="text-xs v-muted">target {{ number_format($slo->target, 2) }}%</span>
                    </div>

                    <div>
                        <div class="flex items-center justify-between text-[11px] v-faint">
                            <span>Error budget</span>
                            <span class="v-num">{{ number_format($slo->budgetRemaining, 0) }}% left</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full" style="background: var(--v-surface-2);"
                             role="img" aria-label="Error budget {{ number_format($slo->budgetRemaining, 0) }} percent remaining">
                            <div class="h-full rounded-full" style="width: {{ max(0, min(100, $slo->budgetRemaining)) }}%; background: var(--{{ $slo->budgetRemaining < 25 ? 'v-danger' : ($slo->budgetRemaining < 60 ? 'v-warn' : 'v-accent') }});"></div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-xs v-muted">
                        <span>Burn rate
                            <span class="font-semibold v-num" style="color: var(--{{ $slo->burnRate >= 2 ? 'v-danger' : 'v-text' }});">{{ number_format($slo->burnRate, 1) }}&times;</span>
                        </span>
                        <span class="v-num v-faint">{{ number_format($slo->events) }} events</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
