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
    <div class="v-page-head">
        <div class="flex min-w-0 flex-wrap items-center gap-3">
            <a href="{{ route('vigilance.runs') }}" class="text-xs v-link">&larr; runs</a>
            @include('vigilance::partials.status', ['status' => $run->status])
            <h1 class="v-page-title">{{ $run->display_name ?: $run->name }}</h1>
        </div>

        <div class="flex items-center gap-2">
            @if ($canRetry)
                <x-vigilance::ui.button wire:click="retry" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="retry">Retry job</span>
                    <span wire:loading wire:target="retry">Retrying…</span>
                </x-vigilance::ui.button>
            @endif

            @if ($canRerun)
                {{-- Confirmed, unlike retry: retrying a failure restores work
                     that was meant to happen, re-running a success creates it a
                     second time — the same charge, the same email, again. --}}
                <x-vigilance::ui.alert-dialog>
                    <x-vigilance::ui.alert-dialog-trigger>
                        <x-vigilance::ui.button variant="outline">Re-run</x-vigilance::ui.button>
                    </x-vigilance::ui.alert-dialog-trigger>

                    <x-vigilance::ui.alert-dialog-content>
                        <x-vigilance::ui.alert-dialog-header>
                            <x-vigilance::ui.alert-dialog-title>Run this again?</x-vigilance::ui.alert-dialog-title>
                            <x-vigilance::ui.alert-dialog-description>
                                {{ $run->display_name ?: $run->name }} will run again with exactly the
                                parameters it ran with. Any side effect it had — a charge, an email, an
                                export — will happen a second time.
                            </x-vigilance::ui.alert-dialog-description>
                        </x-vigilance::ui.alert-dialog-header>
                        <x-vigilance::ui.alert-dialog-footer>
                            <x-vigilance::ui.alert-dialog-cancel>Cancel</x-vigilance::ui.alert-dialog-cancel>
                            <x-vigilance::ui.alert-dialog-action wire:click="rerun">Re-run</x-vigilance::ui.alert-dialog-action>
                        </x-vigilance::ui.alert-dialog-footer>
                    </x-vigilance::ui.alert-dialog-content>
                </x-vigilance::ui.alert-dialog>
            @endif
        </div>
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
            <x-vigilance::ui.card>
                <div class="v-stat__label">{{ $label }}</div>
                <div class="mt-1 truncate font-medium v-strong v-num" title="{{ $value }}">{{ $value }}</div>
            </x-vigilance::ui.card>
        @endforeach
    </div>

    {{-- Timing --}}
    <x-vigilance::ui.card variant="sectioned">
        <x-vigilance::ui.card-header>
            <x-vigilance::ui.card-title>Timing</x-vigilance::ui.card-title>
        </x-vigilance::ui.card-header>
        <x-vigilance::ui.card-content>
            <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-3">
                <div><dt class="v-faint">Queued</dt><dd class="v-num font-mono">{{ $run->queued_at ?: '—' }}</dd></div>
                <div><dt class="v-faint">Started</dt><dd class="v-num font-mono">{{ $run->started_at ?: '—' }}</dd></div>
                <div><dt class="v-faint">Finished</dt><dd class="v-num font-mono">{{ $run->finished_at ?: '—' }}</dd></div>
            </dl>
            @if ($run->uuid)
                <div class="mt-3 text-[10px] v-faint font-mono">uuid {{ $run->uuid }}</div>
            @endif
        </x-vigilance::ui.card-content>
    </x-vigilance::ui.card>

    {{-- Tags --}}
    @if (! empty($run->tags))
        <div class="flex flex-wrap items-center gap-1.5">
            @foreach ($run->tags as $tag)
                <a href="{{ route('vigilance.runs', ['tag' => $tag]) }}" class="v-pill is-neutral font-mono">{{ $tag }}</a>
            @endforeach
        </div>
    @endif

    {{-- Lineage --}}
    @if ($retryOf || $retries->isNotEmpty())
        <x-vigilance::ui.card variant="sectioned">
            <x-vigilance::ui.card-header>
                <x-vigilance::ui.card-title>Lineage</x-vigilance::ui.card-title>
            </x-vigilance::ui.card-header>
            <div class="v-card--pad text-xs">
                @if ($retryOf)
                    <div class="v-muted">Retry of
                        <a href="{{ route('vigilance.runs.show', $retryOf->id) }}" class="v-link">run #{{ $retryOf->id }}</a>
                    </div>
                @endif
                @if ($retries->isNotEmpty())
                    <div class="mt-1 v-muted">Retried by:
                        @foreach ($retries as $child)
                            <a href="{{ route('vigilance.runs.show', $child->id) }}" class="v-link">#{{ $child->id }}</a>@if (! $loop->last), @endif
                        @endforeach
                    </div>
                @endif
            </div>
        </x-vigilance::ui.card>
    @endif

    {{-- Parameters --}}
    @if (! empty($run->parameters))
        <x-vigilance::ui.card variant="sectioned">
            <x-vigilance::ui.card-header><x-vigilance::ui.card-title>Parameters</x-vigilance::ui.card-title></x-vigilance::ui.card-header>
            <pre class="overflow-x-auto p-4 text-xs leading-relaxed font-mono v-muted">{{ json_encode($run->parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </x-vigilance::ui.card>
    @endif

    {{-- Output --}}
    @if (! empty($run->output))
        <x-vigilance::ui.card variant="sectioned">
            <x-vigilance::ui.card-header><x-vigilance::ui.card-title>Output</x-vigilance::ui.card-title></x-vigilance::ui.card-header>
            <pre class="overflow-x-auto p-4 text-xs leading-relaxed font-mono" style="background: var(--v-bg); color: var(--v-accent-strong);">{{ $run->output }}</pre>
        </x-vigilance::ui.card>
    @endif

    {{-- Exception --}}
    @if ($run->exception_class || $run->exception_message)
        <x-vigilance::ui.card variant="sectioned" style="border-color: var(--v-danger);">
            <div class="v-card__header" style="border-color: var(--v-danger);">
                <h2 class="v-card__title" style="color: var(--v-danger);">Exception</h2>
            </div>
            <div class="space-y-2 p-4 text-xs">
                <div class="font-semibold font-mono" style="color: var(--v-danger);">{{ $run->exception_class }}</div>
                <div class="v-muted">{{ $run->exception_message }}</div>

                @if ($run->failure_group_id)
                    <a href="{{ route('vigilance.runs', ['group' => $run->failure_group_id]) }}" class="inline-block v-link">view other runs in this failure group →</a>
                @endif

                @if ($run->exception)
                    <x-vigilance::ui.button variant="ghost" size="sm" class="block" wire:click="toggleTrace">
                        {{ $showTrace ? 'Hide' : 'Show' }} stack trace
                    </x-vigilance::ui.button>
                    @if ($showTrace)
                        <pre class="mt-2 max-h-96 overflow-auto rounded p-4 leading-relaxed font-mono v-muted" style="background: var(--v-bg);">{{ $run->exception }}</pre>
                    @endif
                @endif
            </div>
        </x-vigilance::ui.card>
    @endif
</div>
