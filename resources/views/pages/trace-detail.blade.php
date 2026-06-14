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

<div class="space-y-5">
    <div>
        <a href="{{ route('vigilance.traces') }}" class="text-xs text-emerald-700 hover:underline dark:text-emerald-300">&larr; all traces</a>
    </div>

    {{-- Summary --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span @class([
                    'inline-block h-2 w-2 rounded-full',
                    'bg-red-500' => $trace->failed(),
                    'bg-emerald-500' => ! $trace->failed(),
                ]) aria-hidden="true"></span>
                <h1 class="font-mono text-sm font-semibold">{{ $trace->name }}</h1>
                <span class="rounded bg-zinc-200 px-1.5 py-0.5 text-[10px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $trace->type }}</span>
                <span @class([
                    'rounded px-1.5 py-0.5 text-[10px]',
                    'bg-red-500/10 text-red-700 dark:text-red-300' => $trace->failed(),
                    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => ! $trace->failed(),
                ])>{{ $trace->status }}</span>
            </div>
            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ CarbonImmutable::createFromTimestamp($trace->startedAt)->diffForHumans() }}</span>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div>
                <dt class="text-xs text-zinc-600 dark:text-zinc-400">Duration</dt>
                <dd class="mt-0.5 text-lg font-semibold {{ $trace->durationMs >= (int) config('vigilance.tracing.slow_threshold', 1000) ? 'text-amber-700 dark:text-amber-300' : '' }}">{{ $fmtMs($trace->durationMs) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-zinc-600 dark:text-zinc-400">Spans</dt>
                <dd class="mt-0.5 text-lg font-semibold">{{ $trace->spanCount }}@if ($trace->droppedSpans > 0)<span class="text-xs font-normal text-zinc-500"> (+{{ $trace->droppedSpans }} dropped)</span>@endif</dd>
            </div>
            @foreach ($trace->attributes as $key => $value)
                @if (! is_array($value) && $value !== null && $value !== '')
                    <div>
                        <dt class="text-xs text-zinc-600 dark:text-zinc-400">{{ $key }}</dt>
                        <dd class="mt-0.5 truncate font-mono text-sm">{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
    </div>

    @if ($nPlusOne)
        <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-xs text-amber-800 dark:text-amber-200" role="status">
            <span class="font-semibold">Possible N+1 query.</span>
            The same query ran <span class="font-semibold">{{ $nPlusOne['count'] }}×</span> in this trace:
            <code class="mt-1 block truncate font-mono">{{ $nPlusOne['sql'] }}</code>
        </div>
    @endif

    {{-- Waterfall --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
            <h2 class="text-sm font-semibold">Timeline</h2>
            <div class="flex flex-wrap items-center gap-3 text-[11px] text-zinc-600 dark:text-zinc-400">
                @foreach ($byType as $type => $count)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-2 w-2 rounded-sm {{ $typeBar[$type] ?? 'bg-zinc-400' }}" aria-hidden="true"></span>
                        {{ $type }} ({{ $count }})
                    </span>
                @endforeach
            </div>
        </div>

        @if (count($trace->spans) === 0)
            <p class="px-4 py-10 text-center text-xs text-zinc-600 dark:text-zinc-400">No spans were captured for this trace.</p>
        @else
            <ul class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                @foreach ($trace->spans as $span)
                    @php
                        $left = min(99.6, $span->offsetUs / $totalUs * 100);
                        $width = max(0.4, min(100 - $left, $span->durationUs / $totalUs * 100));
                    @endphp
                    <li class="flex items-center gap-3 px-4 py-1.5">
                        <div class="flex w-2/5 min-w-0 items-center gap-1.5">
                            <span class="shrink-0 rounded px-1 py-0.5 text-[9px] uppercase {{ $typeText[$span->type] ?? 'text-zinc-500' }}">{{ $span->type }}</span>
                            <span class="truncate font-mono text-[11px] text-zinc-700 dark:text-zinc-300" title="{{ $span->label }}">{{ $span->label }}</span>
                        </div>
                        <div class="relative h-3.5 flex-1 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800/60"
                             role="img"
                             aria-label="{{ $span->type }} at {{ $fmtSpan($span->offsetUs) }}, took {{ $fmtSpan($span->durationUs) }}">
                            <span class="absolute top-0 h-full rounded {{ $typeBar[$span->type] ?? 'bg-zinc-400' }}"
                                  style="left: {{ round($left, 2) }}%; width: {{ round($width, 2) }}%"></span>
                        </div>
                        <div class="w-16 shrink-0 text-right font-mono text-[11px] tabular-nums text-zinc-600 dark:text-zinc-400">{{ $fmtSpan($span->durationUs) }}</div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
