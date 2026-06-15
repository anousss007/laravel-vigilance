@php
    $pill = fn (string $rating) => match ($rating) {
        'good' => 'is-success',
        'needs-improvement' => 'is-warn',
        'poor' => 'is-danger',
        default => 'is-neutral',
    };
    $fmt = function (?int $v, string $metric): string {
        if ($v === null) {
            return '—';
        }
        return $metric === 'cls' ? number_format($v / 1000, 2) : ($v < 1000 ? $v.'ms' : number_format($v / 1000, 2).'s');
    };
@endphp

<div wire:poll.visible.15s class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Web Vitals</h1>
            <p class="v-page-sub">Core Web Vitals (p75) from real users — LCP, INP, CLS and timings, per page.</p>
        </div>
    </div>

    @unless ($rumEnabled)
        <div class="v-card v-card--pad" role="note">
            <p class="text-[13px] v-muted">RUM is disabled. Set <code class="v-code">VIGILANCE_RUM=true</code> and add <code class="v-code">@vigilanceRum</code> to your layout <code class="v-code">&lt;head&gt;</code> to start collecting Web Vitals.</p>
        </div>
    @endunless

    <div class="v-card v-card--pad">
        <div class="flex flex-wrap items-center gap-1">
            @foreach (['1h' => 'Last hour', '24h' => 'Last 24h', '7d' => 'Last 7d'] as $key => $label)
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
                        <th scope="col">Page</th>
                        <th scope="col" class="text-right">Samples</th>
                        <th scope="col" class="text-right">LCP</th>
                        <th scope="col" class="text-right">INP</th>
                        <th scope="col" class="text-right">CLS</th>
                        <th scope="col" class="text-right">FCP</th>
                        <th scope="col" class="text-right">TTFB</th>
                        <th scope="col" class="text-right">Rating</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pages as $p)
                        <tr wire:key="vital-{{ $loop->index }}">
                            <td class="max-w-md truncate font-mono v-strong">{{ $p->page }}</td>
                            <td class="text-right v-num v-muted">{{ number_format($p->samples) }}</td>
                            @foreach (['lcp', 'inp', 'cls', 'fcp', 'ttfb'] as $metric)
                                <td class="text-right v-num">
                                    <span @class(['v-pill', $pill($p->rating($metric))])>{{ $fmt($p->$metric, $metric) }}</span>
                                </td>
                            @endforeach
                            <td class="text-right">
                                <span @class(['v-pill', $pill($p->overall())])><span class="v-dot"></span>{{ $p->overall() }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8">
                            <div class="v-empty">
                                <p class="v-empty__title">No Web Vitals yet.</p>
                                <p>Data appears here once real users load pages with the beacon installed.</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
