@php
    $fmtMs = function (?int $ms): string {
        if ($ms === null) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms.'ms';
        }
        return number_format($ms / 1000, 2).'s';
    };
    $fmtBytes = function (?int $bytes): string {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return number_format($value, $i === 0 ? 0 : 1).' '.$units[$i];
    };
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('vigilance.runs') }}" class="text-xs text-zinc-600 dark:text-zinc-400 hover:underline">&larr; runs</a>
            @include('vigilance::partials.status', ['status' => $run->status])
            <h1 class="text-base font-semibold">{{ $run->display_name ?: $run->name }}</h1>
        </div>

        @if ($canRetry)
            <button type="button" wire:click="retry" wire:loading.attr="disabled"
                    class="rounded bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-zinc-950 hover:bg-emerald-400 disabled:opacity-50">
                <span wire:loading.remove wire:target="retry">Retry job</span>
                <span wire:loading wire:target="retry">Retrying…</span>
            </button>
        @endif
    </div>

    {{-- Meta grid --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['Type', $run->type->label()],
            ['Attempt', $run->attempt],
            ['Connection', $run->connection_name ?: '—'],
            ['Queue', $run->queue ?: '—'],
            ['Wait', $fmtMs($run->wait_ms)],
            ['Duration', $fmtMs($run->duration_ms)],
            ['Memory', $fmtBytes($run->memory_peak)],
            ['CPU', $fmtMs($run->cpu_time_ms)],
            ['Via', ($run->via ?: 'auto').($run->caused_by ? ' · '.$run->caused_by : '')],
        ] as [$label, $value])
            <div class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">{{ $label }}</div>
                <div class="mt-1 truncate font-medium" title="{{ $value }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- Timing --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="mb-3 text-sm font-semibold">Timing</h2>
        <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-3">
            <div><dt class="text-zinc-600 dark:text-zinc-400">Queued</dt><dd>{{ $run->queued_at ?: '—' }}</dd></div>
            <div><dt class="text-zinc-600 dark:text-zinc-400">Started</dt><dd>{{ $run->started_at ?: '—' }}</dd></div>
            <div><dt class="text-zinc-600 dark:text-zinc-400">Finished</dt><dd>{{ $run->finished_at ?: '—' }}</dd></div>
        </dl>
        @if ($run->uuid)
            <div class="mt-3 text-[10px] text-zinc-600 dark:text-zinc-400">uuid {{ $run->uuid }}</div>
        @endif
    </div>

    {{-- Tags --}}
    @if (! empty($run->tags))
        <div class="flex flex-wrap items-center gap-1.5">
            @foreach ($run->tags as $tag)
                <a href="{{ route('vigilance.runs', ['tag' => $tag]) }}" class="rounded bg-zinc-200 px-2 py-0.5 text-[10px] text-zinc-600 hover:bg-zinc-300 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700">{{ $tag }}</a>
            @endforeach
        </div>
    @endif

    {{-- Lineage --}}
    @if ($retryOf || $retries->isNotEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-4 text-xs dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="mb-2 text-sm font-semibold">Lineage</h2>
            @if ($retryOf)
                <div class="text-zinc-600 dark:text-zinc-400">Retry of
                    <a href="{{ route('vigilance.runs.show', $retryOf->id) }}" class="text-emerald-700 hover:underline dark:text-emerald-300">run #{{ $retryOf->id }}</a>
                </div>
            @endif
            @if ($retries->isNotEmpty())
                <div class="mt-1 text-zinc-600 dark:text-zinc-400">Retried by:
                    @foreach ($retries as $child)
                        <a href="{{ route('vigilance.runs.show', $child->id) }}" class="text-emerald-700 hover:underline dark:text-emerald-300">#{{ $child->id }}</a>@if (! $loop->last), @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Parameters --}}
    @if (! empty($run->parameters))
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800"><h2 class="text-sm font-semibold">Parameters</h2></div>
            <pre class="overflow-x-auto p-4 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">{{ json_encode($run->parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif

    {{-- Output --}}
    @if (! empty($run->output))
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800"><h2 class="text-sm font-semibold">Output</h2></div>
            <pre class="overflow-x-auto bg-zinc-950 p-4 text-xs leading-relaxed text-emerald-300">{{ $run->output }}</pre>
        </div>
    @endif

    {{-- Exception --}}
    @if ($run->exception_class || $run->exception_message)
        <div class="rounded-lg border border-red-500/40 bg-white dark:bg-zinc-900">
            <div class="border-b border-red-500/40 px-4 py-3">
                <h2 class="text-sm font-semibold text-red-700 dark:text-red-300">Exception</h2>
            </div>
            <div class="space-y-2 p-4 text-xs">
                <div class="font-semibold text-red-700 dark:text-red-300">{{ $run->exception_class }}</div>
                <div class="text-zinc-600 dark:text-zinc-400">{{ $run->exception_message }}</div>

                @if ($run->failure_group_id)
                    <a href="{{ route('vigilance.runs', ['group' => $run->failure_group_id]) }}" class="inline-block text-emerald-700 hover:underline dark:text-emerald-300">view other runs in this failure group →</a>
                @endif

                @if ($run->exception)
                    <button type="button" wire:click="toggleTrace" class="block text-zinc-600 dark:text-zinc-400 hover:underline">
                        {{ $showTrace ? 'Hide' : 'Show' }} stack trace
                    </button>
                    @if ($showTrace)
                        <pre class="mt-2 max-h-96 overflow-auto rounded bg-zinc-950 p-4 leading-relaxed text-zinc-300">{{ $run->exception }}</pre>
                    @endif
                @endif
            </div>
        </div>
    @endif
</div>
