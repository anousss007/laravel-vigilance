{{--
    APM dashboard layout. This view is publishable:
        php artisan vendor:publish --tag=vigilance-views
    …then edit resources/views/vendor/vigilance/apm-dashboard.blade.php to
    rearrange cards, change their grid span (col-span-*), drop cards you don't
    want, or drop in your own <livewire:my-card /> components. Each card is
    lazy-loaded and refreshes itself, so the layout is entirely yours.

    The $period variable is provided by the APM shell.
--}}
<div wire:poll.visible.10s class="grid gap-4 lg:grid-cols-2">
    <div class="lg:col-span-2">
        <livewire:vigilance.apm-card card="servers" :period="$period" :key="'servers-'.$period" />
    </div>

    <livewire:vigilance.apm-card card="slow_requests" :period="$period" :key="'slow_requests-'.$period" />
    <livewire:vigilance.apm-card card="slow_queries" :period="$period" :key="'slow_queries-'.$period" />
    <livewire:vigilance.apm-card card="exceptions" :period="$period" :key="'exceptions-'.$period" />
    <livewire:vigilance.apm-card card="slow_outgoing" :period="$period" :key="'slow_outgoing-'.$period" />
    <livewire:vigilance.apm-card card="cache" :period="$period" :key="'cache-'.$period" />
    <livewire:vigilance.apm-card card="usage" :period="$period" :key="'usage-'.$period" />
    <livewire:vigilance.apm-card card="throughput" :period="$period" :key="'throughput-'.$period" />
    <livewire:vigilance.apm-card card="queues" :period="$period" :key="'queues-'.$period" />

    @if (count((array) config('vigilance.uptime.urls', [])) > 0)
        <div class="lg:col-span-2">
            <livewire:vigilance.apm-card card="uptime" :period="$period" :key="'uptime-'.$period" />
        </div>
    @endif

    <div class="lg:col-span-2">
        <livewire:vigilance.apm-card card="slow_jobs" :period="$period" :key="'slow_jobs-'.$period" />
    </div>
    <div class="lg:col-span-2">
        <livewire:vigilance.apm-card card="sends_logs" :period="$period" :key="'sends_logs-'.$period" />
    </div>
</div>
