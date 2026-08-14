@props(['ranges', 'labels', 'current'])

{{-- The one time-range control, shared by every page that has a window.
     Horizontally scrollable rather than wrapping, so it stays a single row on
     a phone — acknowledging an incident from a handset is a real flow. --}}
<div {{ $attributes->twMerge('flex items-center gap-1 overflow-x-auto') }} role="group" aria-label="Time range">
    @foreach ($ranges as $key)
        <x-vigilance::ui.button
            type="button"
            size="sm"
            variant="{{ $current === $key ? 'default' : 'ghost' }}"
            wire:click="setRange('{{ $key }}')"
            aria-pressed="{{ $current === $key ? 'true' : 'false' }}"
            class="shrink-0"
        >{{ $labels[$key] ?? $key }}</x-vigilance::ui.button>
    @endforeach
</div>
