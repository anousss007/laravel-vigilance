@props(['inset' => false])

<x-vigilance::ui.menu-label
    :data-slot="'dropdown-menu-label'"
    :inset="$inset"
    {{ $attributes }}
>{{ $slot }}</x-vigilance::ui.menu-label>
