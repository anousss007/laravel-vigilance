<div class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Application performance</h1>
            <p class="v-page-sub">Whole-app telemetry — servers, requests, queries, cache, exceptions and more.</p>
        </div>

        <x-vigilance::range-picker :ranges="$this->ranges()" :labels="$this->rangeLabels()" :current="$range" />
    </div>

    {{-- Card layout — publish "vigilance-views" to customise (rearrange / resize / add cards). --}}
    @include('vigilance::apm-dashboard', ['period' => $period])
</div>
