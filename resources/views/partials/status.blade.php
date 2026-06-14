@props(['status'])

@php
    // Full class strings (not interpolated) so the Tailwind Play CDN sees them.
    $classes = match ($status?->color()) {
        'emerald' => 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        'red' => 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300',
        'blue' => 'border-blue-500/40 bg-blue-500/10 text-blue-700 dark:text-blue-300',
        'amber' => 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300',
        'zinc' => 'border-zinc-500/40 bg-zinc-500/10 text-zinc-600 dark:text-zinc-400',
        default => 'border-slate-500/40 bg-slate-500/10 text-slate-700 dark:text-slate-300',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide '.$classes]) }}>
    {{ $status?->value ?? 'unknown' }}
</span>
