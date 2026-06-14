@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between gap-2 text-xs v-muted">
        <span>
            {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }}
        </span>

        <div class="flex items-center gap-2">
            @if (! $paginator->onFirstPage())
                <button type="button" wire:click="previousPage" wire:loading.attr="disabled" rel="prev"
                        class="v-btn v-btn--sm">
                    Prev
                </button>
            @endif

            <span>page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage" wire:loading.attr="disabled" rel="next"
                        class="v-btn v-btn--sm">
                    Next
                </button>
            @endif
        </div>
    </nav>
@endif
