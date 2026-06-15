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

    <link rel="stylesheet" href="{{ route('vigilance.assets.css') }}?v={{ \Vigilance\Vigilance::assetVersion() }}">


    @livewireStyles
</head>
<body>
<a href="#v-main" class="v-skip">Skip to content</a>
@php
    $primary = ['vigilance.overview' => ['label' => 'Overview', 'icon' => 'overview']];

    $groups = [
        'Jobs' => [
            'vigilance.runs'     => ['label' => 'Runs',     'icon' => 'runs'],
            'vigilance.pending'  => ['label' => 'Pending',  'icon' => 'pending'],
            'vigilance.issues' => ['label' => 'Issues', 'icon' => 'failures'],
            'vigilance.batches'  => ['label' => 'Batches',  'icon' => 'batches'],
            'vigilance.tags'     => ['label' => 'Tags',     'icon' => 'tags'],
        ],
        'Workers' => [
            'vigilance.workload' => ['label' => 'Workload', 'icon' => 'workload'],
            'vigilance.workers'  => ['label' => 'Workers',  'icon' => 'workers'],
            'vigilance.schedule' => ['label' => 'Schedule', 'icon' => 'schedule'],
        ],
        'Performance' => [
            'vigilance.apm'     => ['label' => 'APM',     'icon' => 'apm'],
            'vigilance.routes'  => ['label' => 'Routes',  'icon' => 'workload'],
            'vigilance.traces'  => ['label' => 'Traces',  'icon' => 'traces'],
            'vigilance.metrics' => ['label' => 'Metrics', 'icon' => 'metrics'],
        ],
        'Control' => [
            'vigilance.dispatch' => ['label' => 'Dispatch', 'icon' => 'dispatch'],
            'vigilance.commands' => ['label' => 'Commands', 'icon' => 'commands'],
        ],
    ];

    // Flat list for the command palette.
    $paletteItems = [];
    foreach (array_merge(['' => $primary], $groups) as $groupName => $items) {
        foreach ($items as $route => $meta) {
            $paletteItems[] = [
                'label' => $meta['label'],
                'group' => $groupName ?: 'General',
                'url' => route($route),
            ];
        }
    }
@endphp

<div x-data="vigilanceShell(@js($paletteItems))" x-init="init()"
     @keydown.window.meta.k.prevent="openPalette()"
     @keydown.window.ctrl.k.prevent="openPalette()"
     @keydown.window.escape="palette = false; drawer = false"
     class="flex min-h-dvh">

    {{-- Mobile drawer scrim --}}
    <div x-show="drawer" x-cloak @click="drawer = false"
         x-transition.opacity aria-hidden="true"
         class="fixed inset-0 z-40 bg-black/50 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside id="v-sidebar"
        class="v-sidebar fixed inset-y-0 left-0 z-50 flex w-64 flex-col transition-[transform,width] duration-200 lg:static lg:z-auto lg:translate-x-0"
        :class="{ '-translate-x-full': !drawer, 'lg:w-[72px]': collapsed, 'lg:w-64': !collapsed }">

        {{-- Brand --}}
        <div class="flex h-14 shrink-0 items-center gap-2.5 px-4">
            <a href="{{ route('vigilance.overview') }}" class="flex items-center gap-2.5 overflow-hidden">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-500 text-sm font-bold text-zinc-950">V</span>
                <span class="flex items-baseline gap-1.5 whitespace-nowrap" :class="{ 'lg:hidden': collapsed }">
                    <span class="text-[15px] font-semibold v-strong">Vigilance</span>
                    <span class="v-kbd">v{{ \Vigilance\Vigilance::$version }}</span>
                </span>
            </a>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 overflow-y-auto px-2 pb-4" aria-label="Primary">
            <a href="{{ route('vigilance.overview') }}"
               @class(['v-nav-item', 'is-active' => request()->routeIs('vigilance.overview')])
               @if (request()->routeIs('vigilance.overview')) aria-current="page" @endif
               :class="{ 'lg:justify-center': collapsed }"
               title="Overview">
                @include('vigilance::partials.icon', ['name' => 'overview'])
                <span :class="{ 'lg:hidden': collapsed }">Overview</span>
            </a>

            @foreach ($groups as $groupName => $items)
                <p class="v-nav-label" :class="{ 'lg:hidden': collapsed }">{{ $groupName }}</p>
                @foreach ($items as $route => $meta)
                    <a href="{{ route($route) }}"
                       @class(['v-nav-item', 'is-active' => request()->routeIs($route)])
                       @if (request()->routeIs($route)) aria-current="page" @endif
                       :class="{ 'lg:justify-center': collapsed }"
                       title="{{ $meta['label'] }}">
                        @include('vigilance::partials.icon', ['name' => $meta['icon']])
                        <span :class="{ 'lg:hidden': collapsed }">{{ $meta['label'] }}</span>
                    </a>
                @endforeach
            @endforeach
        </nav>

        {{-- Sidebar footer: collapse toggle (desktop) --}}
        <div class="hidden shrink-0 border-t px-2 py-2 lg:block" style="border-color: var(--v-border);">
            <button type="button" @click="toggleCollapsed()" class="v-nav-item w-full"
                    :class="{ 'lg:justify-center': collapsed }" :aria-pressed="collapsed"
                    aria-label="Toggle sidebar width" title="Collapse sidebar">
                @include('vigilance::partials.icon', ['name' => 'sidebar'])
                <span :class="{ 'lg:hidden': collapsed }">Collapse</span>
            </button>
        </div>
    </aside>

    {{-- Main column --}}
    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Top bar --}}
        <header class="v-topbar sticky top-0 z-30 flex h-14 items-center gap-3 px-4 sm:px-6">
            <button type="button" @click="drawer = true" class="v-btn v-btn--ghost v-btn--sm lg:hidden" aria-label="Open menu" aria-controls="v-sidebar" :aria-expanded="drawer">
                @include('vigilance::partials.icon', ['name' => 'menu', 'class' => 'h-5 w-5'])
            </button>

            <span class="truncate text-sm font-semibold v-strong">{{ $title ?? 'Dashboard' }}</span>

            <div class="flex-1"></div>

            <button type="button" @click="openPalette()" aria-label="Search pages" aria-keyshortcuts="Meta+K Control+K"
                    class="hidden items-center gap-2 rounded-lg border px-2.5 py-1.5 text-[13px] v-muted transition-colors hover:v-strong sm:flex"
                    style="background: var(--v-surface-2); border-color: var(--v-border-strong);">
                @include('vigilance::partials.icon', ['name' => 'search', 'class' => 'h-4 w-4'])
                <span>Search</span>
                <span class="v-kbd ml-2">⌘K</span>
            </button>

            <button type="button" @click="toggleTheme()" class="v-btn v-btn--ghost v-btn--sm" aria-label="Toggle theme" :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'" :aria-pressed="dark">
                <template x-if="dark">@include('vigilance::partials.icon', ['name' => 'sun', 'class' => 'h-5 w-5'])</template>
                <template x-if="!dark">@include('vigilance::partials.icon', ['name' => 'moon', 'class' => 'h-5 w-5'])</template>
            </button>
        </header>

        {{-- Flash --}}
        @if ($flash = session('vigilance.flash'))
            <div class="px-4 pt-4 sm:px-6">
                <div @class([
                    'mx-auto max-w-[1400px] rounded-lg border px-4 py-2.5 text-[13px] v-pill',
                    'is-success' => ($flash['type'] ?? 'success') === 'success',
                    'is-danger' => ($flash['type'] ?? '') === 'error',
                ]) role="status" style="display:flex; border-color: var(--v-border);">
                    {{ $flash['message'] ?? '' }}
                </div>
            </div>
        @endif

        {{-- Content --}}
        <main id="v-main" tabindex="-1" class="flex-1 px-4 py-6 sm:px-6 focus:outline-none">
            <div class="mx-auto max-w-[1400px]">
                {{ $slot }}
            </div>
        </main>
    </div>

    {{-- Command palette --}}
    <div x-show="palette" x-cloak class="fixed inset-0 z-[100]" @keydown.escape.window="palette = false">
        <div x-show="palette" x-transition.opacity class="absolute inset-0 bg-black/50" @click="palette = false" aria-hidden="true"></div>
        <div x-show="palette" x-transition role="dialog" aria-modal="true" aria-label="Command palette"
             class="absolute left-1/2 top-[12vh] w-[92vw] max-w-xl -translate-x-1/2 v-palette overflow-hidden">
            <div class="flex items-center gap-3 border-b px-4" style="border-color: var(--v-border);">
                @include('vigilance::partials.icon', ['name' => 'search', 'class' => 'h-4 w-4 v-faint'])
                <input x-ref="paletteInput" x-model="q" @input="active = 0"
                       @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="go()"
                       type="text" placeholder="Jump to a page…" aria-label="Search pages"
                       class="w-full bg-transparent py-3.5 text-sm outline-none v-strong" style="color: var(--v-text-strong);">
                <span class="v-kbd">esc</span>
            </div>
            <div class="max-h-[50vh] overflow-y-auto p-2">
                <template x-for="(item, i) in filtered" :key="item.url">
                    <a :href="item.url" @mouseenter="active = i"
                       class="v-palette__item" :class="{ 'is-active': active === i }">
                        <span class="text-[10px] font-semibold uppercase tracking-wider v-faint" x-text="item.group"></span>
                        <span class="font-medium" x-text="item.label"></span>
                        <span class="ml-auto" x-show="active === i">
                            @include('vigilance::partials.icon', ['name' => 'corner-down-left', 'class' => 'h-3.5 w-3.5 v-faint'])
                        </span>
                    </a>
                </template>
                <p x-show="filtered.length === 0" class="px-3 py-6 text-center text-[13px] v-muted">No matching page.</p>
            </div>
        </div>
    </div>
</div>

<script>
    window.vigilanceShell = function (items) {
        return {
            items: items,
            drawer: false,
            collapsed: false,
            palette: false,
            q: '',
            active: 0,
            dark: true,
            init() {
                try { this.collapsed = localStorage.getItem('vigilance-sidebar') === 'collapsed'; } catch (e) {}
                this.dark = document.documentElement.classList.contains('dark');
            },
            get filtered() {
                const q = this.q.trim().toLowerCase();
                if (!q) return this.items;
                return this.items.filter(i => (i.label + ' ' + i.group).toLowerCase().includes(q));
            },
            toggleCollapsed() {
                this.collapsed = !this.collapsed;
                try { localStorage.setItem('vigilance-sidebar', this.collapsed ? 'collapsed' : 'expanded'); } catch (e) {}
            },
            toggleTheme() {
                this.dark = document.documentElement.classList.toggle('dark');
                try { localStorage.setItem('vigilance-theme', this.dark ? 'dark' : 'light'); } catch (e) {}
            },
            openPalette() {
                this.palette = true; this.q = ''; this.active = 0;
                this.$nextTick(() => { if (this.$refs.paletteInput) this.$refs.paletteInput.focus(); });
            },
            move(d) {
                const n = this.filtered.length;
                if (!n) return;
                this.active = (this.active + d + n) % n;
            },
            go() {
                const f = this.filtered;
                if (f[this.active]) window.location = f[this.active].url;
            },
        };
    };
</script>

@livewireScripts
</body>
</html>
