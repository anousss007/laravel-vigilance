@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between gap-2 text-xs v-muted">
        <span>
            {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }}
        </span>

        <div class="flex items-center gap-2">
            @if (! $paginator->onFirstPage())
                <x-vigilance::ui.button variant="outline" size="sm" wire:click="previousPage" wire:loading.attr="disabled" rel="prev">
                    Prev
                </x-vigilance::ui.button>
            @endif

            <span>page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <x-vigilance::ui.button variant="outline" size="sm" wire:click="nextPage" wire:loading.attr="disabled" rel="next">
                    Next
                </x-vigilance::ui.button>
            @endif
        </div>
    </nav>
@endif
