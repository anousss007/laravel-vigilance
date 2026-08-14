@props(['variant' => 'default'])   {{-- default | card (bordered, rounded, on bg-card) --}}

@php
    $container = $variant === 'card'
        ? 'relative w-full overflow-x-auto rounded-lg border bg-card shadow-xs'
        : 'relative w-full overflow-x-auto';
@endphp

{{-- tabindex: a horizontally scrollable region has to be focusable, or a
     keyboard user cannot reach the columns that overflow. --}}
<div data-slot="table-container" data-variant="{{ $variant }}" class="{{ $container }}" tabindex="0">
    <table data-slot="table" {{ $attributes->twMerge('w-full caption-bottom text-sm') }}>
        {{ $slot }}
    </table>
</div>
