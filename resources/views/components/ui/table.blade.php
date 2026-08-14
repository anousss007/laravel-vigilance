@props(['variant' => 'default'])   {{-- default | card (bordered, rounded, on bg-card) --}}

@php
    $container = $variant === 'card'
        ? 'relative w-full overflow-x-auto rounded-lg border bg-card shadow-xs'
        : 'relative w-full overflow-x-auto';
@endphp

{{-- tabindex: a horizontally scrollable region has to be focusable, or a
     keyboard user cannot reach the columns that overflow. --}}
<div data-slot="table-container" data-variant="{{ $variant }}" class="{{ $container }}" tabindex="0">
    {{-- v-table carries the cell styling (padding, borders, header treatment) as
         descendant rules, so a page can write plain <th>/<td> inside — which is
         what every dashboard table does — instead of wrapping each cell in a
         component. Utilities still win over it: the .v-table rules live in the
         components layer, so a `class="text-right"` on a cell is not overridden. --}}
    <table data-slot="table" {{ $attributes->twMerge('v-table v-table--hover w-full caption-bottom') }}>
        {{ $slot }}
    </table>
</div>
