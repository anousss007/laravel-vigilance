<div @vigilancePoll('5s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Batches</h1>
            <p class="v-page-sub">Bus::batch() progress (any driver)</p>
        </div>
    </div>

    @unless ($supported)
        <x-vigilance::ui.card class="text-[13px]" style="border-color: var(--v-warn); color: var(--v-warn);">
            Job batching isn't set up. Run <code class="v-code">php artisan make:queue-batches-table</code>
            (or <code class="v-code">queue:batches-table</code>) and migrate.
        </x-vigilance::ui.card>
    @endunless

    @if ($supported)
        <div class="space-y-4">
            @forelse ($batches as $batch)
                @php
                    $progress = (int) $batch->progress();
                    $processed = $batch->processedJobs();
                    $state = $batch->cancelled() ? 'cancelled' : ($batch->finished() ? 'finished' : 'processing');
                @endphp
                <x-vigilance::ui.card>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="font-medium v-strong">{{ $batch->name ?: 'Unnamed batch' }}</span>
                            <span @class([
                                'v-pill',
                                'is-success' => $state === 'finished',
                                'is-running' => $state === 'processing',
                                'is-neutral' => $state === 'cancelled',
                            ])><span class="v-dot"></span>{{ $state }}</span>
                            @if ($batch->failedJobs > 0)
                                <x-vigilance::ui.badge tone="danger" class="v-num">{{ $batch->failedJobs }} failed</x-vigilance::ui.badge>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($batch->failedJobs > 0)
                                <button type="button" wire:click="retry('{{ $batch->id }}')"
                                        class="v-btn v-btn--sm">
                                    Retry failed
                                </button>
                            @endif
                            @if ($state === 'processing')
                                <button type="button" wire:click="cancel('{{ $batch->id }}')"
                                        class="v-btn v-btn--sm v-btn--danger">
                                    Cancel
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 flex items-center gap-3">
                        <div class="h-2 flex-1 overflow-hidden rounded-full" style="background: var(--v-surface-2);" role="img" aria-label="Batch {{ $progress }}% complete">
                            <div class="h-full rounded-full" style="width: {{ $progress }}%; background: {{ $batch->failedJobs > 0 ? 'var(--v-danger)' : 'var(--v-success)' }};"></div>
                        </div>
                        <span class="shrink-0 text-xs v-num v-muted">{{ $progress }}%</span>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-[11px] v-faint">
                        <span class="v-num">{{ $processed }}/{{ $batch->totalJobs }} processed · {{ $batch->pendingJobs }} pending</span>
                        <span>{{ optional($batch->createdAt)->diffForHumans() }}</span>
                    </div>
                </x-vigilance::ui.card>
            @empty
                <x-vigilance::ui.empty>
    <x-vigilance::ui.empty-title>No batches yet.</x-vigilance::ui.empty-title>
</x-vigilance::ui.empty>
            @endforelse
        </div>
    @endif
</div>
