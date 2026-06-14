@props(['status'])

@php
    $state = match ($status?->color()) {
        'emerald' => 'is-success',
        'red' => 'is-danger',
        'blue' => 'is-info',
        'amber' => 'is-warn',
        default => 'is-neutral',
    };
@endphp

<span {{ $attributes->merge(['class' => 'v-pill '.$state.' uppercase tracking-wide']) }}>
    <span class="v-dot"></span>{{ $status?->value ?? 'unknown' }}
</span>
