@php
    $fmtMs = function (int $ms): string {
        if ($ms < 1000) {
            return $ms.'ms';
        }
        return number_format($ms / 1000, 2).'s';
    };
@endphp

<div class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Traces</h1>
            <p class="v-page-sub">Request &amp; job timelines (sampled).</p>
        </div>
    </div>

    @unless ($enabled)
        <div class="v-card v-card--pad text-[13px]" style="border-color: var(--v-warn); background: var(--v-warn-bg); color: var(--v-warn);">
            Tracing is currently disabled. Enable it with <code class="v-code">VIGILANCE_TRACING=true</code>
            (it stores only slow or failed requests by default). Existing traces below are still browsable.
        </div>
    @endunless

    {{-- Filters --}}
    <div class="v-card v-card--pad">
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex flex-col gap-1">
                <label for="trace-type" class="v-label">Type</label>
                <select id="trace-type" wire:model.live="type" class="v-select">
                    <option value="">All</option>
                    <option value="request">Request</option>
                    <option value="job">Job</option>
                    <option value="command">Command</option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label for="trace-status" class="v-label">Status</label>
                <select id="trace-status" wire:model.live="status" class="v-select">
                    <option value="">All</option>
                    <option value="ok">OK</option>
                    <option value="error">Error</option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label for="trace-q" class="v-label">Search</label>
                <input id="trace-q" type="search" wire:model.live.debounce.400ms="q" placeholder="name…" class="v-input">
            </div>
            <label class="flex items-center gap-1.5 text-[13px] v-muted">
                <input type="checkbox" wire:model.live="slowOnly" class="v-checkbox">
                Slow only
            </label>
            <button type="button" wire:click="clear" class="v-btn v-btn--ghost v-btn--sm ml-auto">Clear</button>
        </div>
    </div>

    <div class="v-card overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="v-table v-table--hover">
                <caption class="sr-only">Recent traces</caption>
                <thead>
                    <tr>
                        <th scope="col">Type</th>
                        <th scope="col">Name</th>
                        <th scope="col" class="text-right">Spans</th>
                        <th scope="col" class="text-right">Duration</th>
                        <th scope="col" class="text-right">When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($traces as $trace)
                        <tr>
                            <td>
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="inline-block h-1.5 w-1.5 rounded-full" aria-hidden="true"
                                          style="background: {{ $trace->failed() ? 'var(--v-danger)' : 'var(--v-success)' }};"></span>
                                    <span class="v-pill is-neutral">{{ $trace->type }}</span>
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('vigilance.traces.show', $trace->id) }}" class="font-mono font-medium v-strong hover:underline">
                                    {{ $trace->name }}
                                </a>
                                @if ($trace->failed())
                                    <span class="v-pill is-danger ml-1.5">failed</span>
                                @endif
                                @if (! empty($trace->attributes['n_plus_one']))
                                    <span class="v-pill is-warn ml-1.5">N+1</span>
                                @endif
                            </td>
                            <td class="text-right v-num v-muted">
                                {{ $trace->spanCount }}@if ($trace->droppedSpans > 0)<span class="v-faint" title="dropped at the span cap">+{{ $trace->droppedSpans }}</span>@endif
                            </td>
                            <td class="text-right v-num font-medium font-mono"
                                @if ($trace->durationMs >= (int) config('vigilance.tracing.slow_threshold', 1000)) style="color: var(--v-warn);" @endif>
                                {{ $fmtMs($trace->durationMs) }}
                            </td>
                            <td class="text-right v-muted">
                                {{ \Carbon\CarbonImmutable::createFromTimestamp($trace->startedAt)->diffForHumans() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center v-muted">
                                @if ($enabled && $sampleRate <= 0)
                                    No traces yet — tracing is on, but with
                                    <code class="v-code">VIGILANCE_TRACING_SAMPLE=0</code>
                                    only slow (&ge;{{ $slowThreshold }}ms) and failed requests are stored.
                                    Raise it (e.g. <code class="v-code">1.0</code> locally)
                                    to capture normal requests too.
                                @else
                                    No traces recorded yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
