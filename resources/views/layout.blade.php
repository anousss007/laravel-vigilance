<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ($title ?? 'Dashboard') }} &middot; Vigilance</title>

    {{-- Set the theme before paint to avoid a flash of the wrong mode. --}}
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('vigilance-theme');
                var dark = stored ? stored === 'dark' : true;
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>

    <link rel="stylesheet" href="{{ route('vigilance.assets.css') }}?v={{ \Vigilance\Vigilance::$version }}">

    @livewireStyles
</head>
<body class="min-h-screen bg-zinc-50 font-mono text-sm text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-200">
    @php
        $nav = [
            'vigilance.overview' => 'Overview',
            'vigilance.apm' => 'APM',
            'vigilance.traces' => 'Traces',
            'vigilance.runs' => 'Runs',
            'vigilance.failures' => 'Failures',
            'vigilance.tags' => 'Tags',
            'vigilance.dispatch' => 'Dispatch',
            'vigilance.commands' => 'Commands',
            'vigilance.schedule' => 'Schedule',
            'vigilance.workload' => 'Workload',
            'vigilance.workers' => 'Workers',
            'vigilance.pending' => 'Pending',
            'vigilance.batches' => 'Batches',
            'vigilance.metrics' => 'Metrics',
        ];
    @endphp

    <header class="sticky top-0 z-30 border-b border-zinc-200 bg-white/90 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/90">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <a href="{{ route('vigilance.overview') }}" class="flex items-center gap-2 font-semibold tracking-tight">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded bg-emerald-500 text-xs font-bold text-zinc-950">V</span>
                <span>Vigilance</span>
                <span class="rounded bg-zinc-200 px-1.5 py-0.5 text-[10px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                    v{{ \Vigilance\Vigilance::$version }}
                </span>
            </a>

            <nav class="flex flex-1 flex-wrap items-center gap-1">
                @foreach ($nav as $route => $label)
                    <a href="{{ route($route) }}"
                       @class([
                           'rounded px-2.5 py-1.5 text-xs transition-colors',
                           'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' => request()->routeIs($route),
                           'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100' => ! request()->routeIs($route),
                       ])>
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <button type="button"
                    onclick="(function(){var d=document.documentElement.classList.toggle('dark');try{localStorage.setItem('vigilance-theme', d?'dark':'light');}catch(e){}})()"
                    class="rounded border border-zinc-300 px-2.5 py-1.5 text-xs text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800"
                    title="Toggle dark mode">
                <span class="hidden dark:inline">&#9788; light</span>
                <span class="inline dark:hidden">&#9789; dark</span>
            </button>
        </div>
    </header>

    @if ($flash = session('vigilance.flash'))
        <div class="mx-auto max-w-7xl px-4 pt-4">
            <div @class([
                'rounded-md border px-4 py-2.5 text-xs',
                'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => ($flash['type'] ?? 'success') === 'success',
                'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300' => ($flash['type'] ?? '') === 'error',
            ])>
                {{ $flash['message'] ?? '' }}
            </div>
        </div>
    @endif

    <main class="mx-auto max-w-7xl px-4 py-6">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
