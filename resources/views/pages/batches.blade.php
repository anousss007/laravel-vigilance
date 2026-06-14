<div wire:poll.visible.5s class="space-y-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h1 class="text-base font-semibold">Batches</h1>
        <span class="text-xs text-zinc-600 dark:text-zinc-400">Bus::batch() progress (any driver)</span>
    </div>

    @unless ($supported)
        <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-xs text-amber-800 dark:text-amber-200">
            Job batching isn't set up. Run <code class="rounded bg-amber-500/10 px-1 py-0.5">php artisan make:queue-batches-table</code>
            (or <code class="rounded bg-amber-500/10 px-1 py-0.5">queue:batches-table</code>) and migrate.
        </div>
    @endunless

    @if ($supported)
        <div class="space-y-3">
            @forelse ($batches as $batch)
                @php
                    $progress = (int) $batch->progress();
                    $processed = $batch->processedJobs();
                    $state = $batch->cancelled() ? 'cancelled' : ($batch->finished() ? 'finished' : 'processing');
                @endphp
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ $batch->name ?: 'Unnamed batch' }}</span>
                            <span @class([
                                'rounded px-1.5 py-0.5 text-[10px]',
                                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $state === 'finished',
                                'bg-blue-500/10 text-blue-700 dark:text-blue-300' => $state === 'processing',
                                'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' => $state === 'cancelled',
                            ])>{{ $state }}</span>
                            @if ($batch->failedJobs > 0)
                                <span class="rounded bg-red-500/10 px-1.5 py-0.5 text-[10px] text-red-700 dark:text-red-300">{{ $batch->failedJobs }} failed</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($batch->failedJobs > 0)
                                <button type="button" wire:click="retry('{{ $batch->id }}')"
                                        class="rounded border border-zinc-300 px-2 py-1 text-[11px] text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                    Retry failed
                                </button>
                            @endif
                            @if ($state === 'processing')
                                <button type="button" wire:click="cancel('{{ $batch->id }}')"
                                        class="rounded border border-red-300 px-2 py-1 text-[11px] text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/40">
                                    Cancel
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 flex items-center gap-3">
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800" role="img" aria-label="Batch {{ $progress }}% complete">
                            <div @class([
                                'h-full rounded-full',
                                'bg-emerald-500' => $batch->failedJobs === 0,
                                'bg-red-500' => $batch->failedJobs > 0,
                            ]) style="width: {{ $progress }}%"></div>
                        </div>
                        <span class="shrink-0 text-xs tabular-nums text-zinc-600 dark:text-zinc-400">{{ $progress }}%</span>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-[11px] text-zinc-500 dark:text-zinc-400">
                        <span class="tabular-nums">{{ $processed }}/{{ $batch->totalJobs }} processed · {{ $batch->pendingJobs }} pending</span>
                        <span>{{ optional($batch->createdAt)->diffForHumans() }}</span>
                    </div>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-zinc-300 bg-white px-4 py-10 text-center text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                    No batches yet.
                </div>
            @endforelse
        </div>
    @endif
</div>
