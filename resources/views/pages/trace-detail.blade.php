@php
    use Carbon\CarbonImmutable;

    $fmtMs = function (int $ms): string {
        if ($ms < 1000) {
            return $ms.'ms';
        }
        return number_format($ms / 1000, 2).'s';
    };

    $fmtSpan = function (int $us): string {
        if ($us < 1000) {
            return $us.'µs';
        }
        if ($us < 1_000_000) {
            return number_format($us / 1000, 1).'ms';
        }
        return number_format($us / 1_000_000, 2).'s';
    };

    $typeBar = [
        'query' => 'bg-blue-500',
        'cache' => 'bg-violet-500',
        'http' => 'bg-amber-500',
        'redis' => 'bg-rose-500',
        'mail' => 'bg-teal-500',
        'notification' => 'bg-cyan-500',
        'exception' => 'bg-red-500',
    ];
    $typeText = [
        'query' => 'text-blue-700 dark:text-blue-300',
        'cache' => 'text-violet-700 dark:text-violet-300',
        'http' => 'text-amber-700 dark:text-amber-300',
        'redis' => 'text-rose-700 dark:text-rose-300',
        'mail' => 'text-teal-700 dark:text-teal-300',
        'notification' => 'text-cyan-700 dark:text-cyan-300',
        'exception' => 'text-red-700 dark:text-red-300',
    ];

    $totalUs = max(1, $trace->durationMs * 1000);

    $byType = collect($trace->spans)->groupBy('type')->map->count();
    $nPlusOne = $trace->attributes['n_plus_one'] ?? null;
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('vigilance.traces') }}" class="text-xs v-link">&larr; all traces</a>
    </div>

    {{-- Summary --}}
    <div class="v-card v-card--pad">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-block h-2 w-2 rounded-full" aria-hidden="true"
                      style="background: {{ $trace->failed() ? 'var(--v-danger)' : 'var(--v-success)' }};"></span>
                <h1 class="font-mono text-sm font-semibold v-strong">{{ $trace->name }}</h1>
                <span class="v-pill is-neutral">{{ $trace->type }}</span>
                <span @class(['v-pill', 'is-danger' => $trace->failed(), 'is-success' => ! $trace->failed()])>{{ $trace->status }}</span>
            </div>
            <span class="text-xs v-muted">{{ CarbonImmutable::createFromTimestamp($trace->startedAt)->diffForHumans() }}</span>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <dt class="v-stat__label">Duration</dt>
                <dd class="mt-1 text-lg font-semibold v-num font-mono" @if ($trace->durationMs >= (int) config('vigilance.tracing.slow_threshold', 1000)) style="color: var(--v-warn)" @else style="color: var(--v-text-strong)" @endif>{{ $fmtMs($trace->durationMs) }}</dd>
            </div>
            <div>
                <dt class="v-stat__label">Spans</dt>
                <dd class="mt-1 text-lg font-semibold v-strong v-num">{{ $trace->spanCount }}@if ($trace->droppedSpans > 0)<span class="text-xs font-normal v-faint"> (+{{ $trace->droppedSpans }} dropped)</span>@endif</dd>
            </div>
            @foreach ($trace->attributes as $key => $value)
                @if (! is_array($value) && $value !== null && $value !== '')
                    <div>
                        <dt class="v-stat__label">{{ $key }}</dt>
                        <dd class="mt-1 truncate font-mono text-sm v-strong">{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
    </div>

    @if ($nPlusOne)
        <div class="v-card v-card--pad text-xs" role="status" style="border-color: var(--v-warn); background: var(--v-warn-bg); color: var(--v-warn);">
            <span class="font-semibold">Possible N+1 query.</span>
            The same query ran <span class="font-semibold">{{ $nPlusOne['count'] }}×</span> in this trace:
            <code class="mt-1 block truncate font-mono">{{ $nPlusOne['sql'] }}</code>
        </div>
    @endif

    {{-- Waterfall --}}
    <div class="v-card overflow-hidden">
        <div class="v-card__header">
            <h2 class="v-card__title">Timeline</h2>
            <div class="flex flex-wrap items-center gap-3 text-[11px] v-muted">
                @foreach ($byType as $type => $count)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-2 w-2 rounded-sm {{ $typeBar[$type] ?? 'bg-zinc-400' }}" aria-hidden="true"></span>
                        {{ $type }} ({{ $count }})
                    </span>
                @endforeach
            </div>
        </div>

        @if (count($trace->spans) === 0)
            <p class="px-4 py-10 text-center text-xs v-muted">No spans were captured for this trace.</p>
        @else
            <ul>
                @foreach ($trace->spans as $span)
                    @php
                        $left = min(99.6, $span->offsetUs / $totalUs * 100);
                        $width = max(0.4, min(100 - $left, $span->durationUs / $totalUs * 100));
                    @endphp
                    <li class="flex items-center gap-3 px-4 py-1.5">
                        <div class="flex w-2/5 min-w-0 items-center gap-1.5">
                            <span class="shrink-0 rounded px-1 py-0.5 text-[9px] uppercase {{ $typeText[$span->type] ?? 'text-zinc-500' }}">{{ $span->type }}</span>
                            <span class="truncate font-mono text-[11px] v-muted" title="{{ $span->label }}">{{ $span->label }}</span>
                        </div>
                        <div class="relative h-3.5 flex-1 overflow-hidden rounded"
                             style="background: var(--v-surface-2);"
                             role="img"
                             aria-label="{{ $span->type }} at {{ $fmtSpan($span->offsetUs) }}, took {{ $fmtSpan($span->durationUs) }}">
                            <span class="absolute top-0 h-full rounded {{ $typeBar[$span->type] ?? 'bg-zinc-400' }}"
                                  style="left: {{ round($left, 2) }}%; width: {{ round($width, 2) }}%"></span>
                        </div>
                        <div class="w-16 shrink-0 text-right font-mono text-[11px] v-num v-muted">{{ $fmtSpan($span->durationUs) }}</div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
