@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between gap-2 text-xs">
        <span class="text-zinc-600 dark:text-zinc-400">
            {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }}
        </span>

        <div class="flex items-center gap-2">
            @if (! $paginator->onFirstPage())
                <button type="button" wire:click="previousPage" wire:loading.attr="disabled" rel="prev"
                        class="rounded border border-zinc-300 px-2.5 py-1 text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    Prev
                </button>
            @endif

            <span class="text-zinc-600 dark:text-zinc-400">page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage" wire:loading.attr="disabled" rel="next"
                        class="rounded border border-zinc-300 px-2.5 py-1 text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    Next
                </button>
            @endif
        </div>
    </nav>
@endif
