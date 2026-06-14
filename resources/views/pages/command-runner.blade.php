<div class="space-y-6">
    <h1 class="text-base font-semibold">Run a command</h1>

    @unless ($enabled)
        <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-xs text-amber-700 dark:text-amber-300">
            Manual control is disabled. Set <code class="rounded bg-amber-500/20 px-1">vigilance.control.enabled</code> to <code class="rounded bg-amber-500/20 px-1">true</code> to run commands from here.
        </div>
    @else
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <label class="flex flex-col gap-1">
                <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Command</span>
                <select wire:model.live="command" class="rounded border border-zinc-300 bg-transparent px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900">
                    <option value="">Select a command…</option>
                    @foreach ($commands as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>

            @if ($commands === [])
                <p class="mt-3 text-xs text-zinc-600 dark:text-zinc-400">No commands are allowed. Configure <code>vigilance.control.commands</code>.</p>
            @endif

            @if ($command !== '')
                <form wire:submit="runCommand" class="mt-4 space-y-4 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                    @if (! empty($schema['arguments']))
                        <div class="space-y-3">
                            <h3 class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Arguments</h3>
                            @foreach ($schema['arguments'] as $argument)
                                <div>
                                    <div class="mb-1 flex items-center gap-2 text-xs">
                                        <span class="font-medium">{{ $argument['name'] }}</span>
                                        @if ($argument['required'])<span class="text-red-500">*</span>@endif
                                        @if ($argument['is_array'])<span class="text-[10px] text-zinc-600 dark:text-zinc-400">array (comma-separated)</span>@endif
                                    </div>
                                    @if ($argument['description'])
                                        <p class="mb-1 text-[10px] text-zinc-600 dark:text-zinc-400">{{ $argument['description'] }}</p>
                                    @endif
                                    <input type="text" wire:model="arguments.{{ $argument['name'] }}"
                                           class="w-full rounded border border-zinc-300 bg-transparent px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700">
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($schema['options']))
                        <div class="space-y-3">
                            <h3 class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Options</h3>
                            @foreach ($schema['options'] as $option)
                                <div>
                                    @if ($option['accept_value'])
                                        <div class="mb-1 flex items-center gap-2 text-xs">
                                            <span class="font-medium">--{{ $option['name'] }}</span>
                                            @if ($option['shortcut'])<span class="text-[10px] text-zinc-600 dark:text-zinc-400">-{{ $option['shortcut'] }}</span>@endif
                                        </div>
                                        @if ($option['description'])
                                            <p class="mb-1 text-[10px] text-zinc-600 dark:text-zinc-400">{{ $option['description'] }}</p>
                                        @endif
                                        <input type="text" wire:model="options.{{ $option['name'] }}"
                                               class="w-full rounded border border-zinc-300 bg-transparent px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700">
                                    @else
                                        <label class="flex items-center gap-2 text-xs">
                                            <input type="checkbox" wire:model="options.{{ $option['name'] }}" class="rounded border-zinc-400 text-emerald-500 focus:ring-emerald-500">
                                            <span class="font-medium">--{{ $option['name'] }}</span>
                                            @if ($option['description'])<span class="text-[10px] text-zinc-600 dark:text-zinc-400">{{ $option['description'] }}</span>@endif
                                        </label>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex items-center justify-between border-t border-zinc-200 pt-3 dark:border-zinc-800">
                        <label class="flex items-center gap-2 text-xs">
                            <input type="checkbox" wire:model="background" class="rounded border-zinc-400 text-emerald-500 focus:ring-emerald-500">
                            Run in background (queue)
                        </label>
                        <button type="submit" wire:loading.attr="disabled"
                                class="rounded bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-zinc-950 hover:bg-emerald-400 disabled:opacity-50">
                            <span wire:loading.remove wire:target="runCommand">Run</span>
                            <span wire:loading wire:target="runCommand">Running…</span>
                        </button>
                    </div>
                </form>
            @endif
        </div>

        @if ($exitCode !== null)
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                    <h2 class="text-sm font-semibold">Output</h2>
                    <span @class([
                        'rounded px-1.5 py-0.5 text-[10px]',
                        'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $exitCode === 0,
                        'bg-red-500/10 text-red-700 dark:text-red-300' => $exitCode !== 0,
                    ])>exit {{ $exitCode }}</span>
                </div>
                <pre class="overflow-x-auto bg-zinc-950 p-4 text-xs leading-relaxed text-emerald-300">{{ $output !== '' ? $output : '(no output)' }}</pre>
            </div>
        @endif
    @endunless
</div>
