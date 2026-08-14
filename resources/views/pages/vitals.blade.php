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

<div @vigilancePoll('15s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Web Vitals</h1>
            <p class="v-page-sub">Core Web Vitals (p75) from real users — LCP, INP, CLS and timings, per page.</p>
        </div>
    </div>

    @unless ($rumEnabled)
        <x-vigilance::ui.card role="note">
            <p class="text-[13px] v-muted">RUM is disabled. Set <code class="v-code">VIGILANCE_RUM=true</code> and add <code class="v-code">@vigilanceRum</code> to your layout <code class="v-code">&lt;head&gt;</code> to start collecting Web Vitals.</p>
        </x-vigilance::ui.card>
    @endunless

    <x-vigilance::ui.card class="p-2">
        <x-vigilance::range-picker :ranges="$this->ranges()" :labels="$this->rangeLabels()" :current="$range" />
    </x-vigilance::ui.card>

    <x-vigilance::ui.card class="overflow-hidden">
        <x-vigilance::ui.table>
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
                            <x-vigilance::ui.empty>
    <x-vigilance::ui.empty-title>No Web Vitals yet.</x-vigilance::ui.empty-title>
    <x-vigilance::ui.empty-description><p>Data appears here once real users load pages with the beacon installed.</p></x-vigilance::ui.empty-description>
</x-vigilance::ui.empty>
                        </td></tr>
                    @endforelse
                </tbody>
            </x-vigilance::ui.table>
    </x-vigilance::ui.card>
</div>
