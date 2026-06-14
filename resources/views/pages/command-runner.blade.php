<div class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Run a command</h1>
            <p class="v-page-sub">Run an allowlisted artisan command with reflected arguments and options.</p>
        </div>
    </div>

    @unless ($enabled)
        <div class="v-card v-card--pad text-[13px]" style="border-color: var(--v-warn); background: var(--v-warn-bg); color: var(--v-warn);">
            Manual control is disabled. Set <code class="v-code">vigilance.control.enabled</code> to <code class="v-code">true</code> to run commands from here.
        </div>
    @else
        <div class="v-card v-card--pad">
            <label class="block">
                <span class="v-label">Command</span>
                <select wire:model.live="command" class="v-select">
                    <option value="">Select a command…</option>
                    @foreach ($commands as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>

            @if ($commands === [])
                <p class="mt-3 text-xs v-muted">No commands are allowed. Configure <code class="v-code">vigilance.control.commands</code>.</p>
            @endif

            @if ($command !== '')
                <form wire:submit="runCommand" class="mt-4 space-y-4 border-t pt-4" style="border-color: var(--v-border);">
                    @if (! empty($schema['arguments']))
                        <div class="space-y-3">
                            <h3 class="v-label">Arguments</h3>
                            @foreach ($schema['arguments'] as $argument)
                                <div>
                                    <div class="mb-1 flex items-center gap-2 text-xs">
                                        <span class="font-medium v-strong font-mono">{{ $argument['name'] }}</span>
                                        @if ($argument['required'])<span style="color: var(--v-danger)">*</span>@endif
                                        @if ($argument['is_array'])<span class="text-[10px] v-faint">array (comma-separated)</span>@endif
                                    </div>
                                    @if ($argument['description'])
                                        <p class="mb-1 text-[10px] v-faint">{{ $argument['description'] }}</p>
                                    @endif
                                    <input type="text" wire:model="arguments.{{ $argument['name'] }}" class="v-input">
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($schema['options']))
                        <div class="space-y-3">
                            <h3 class="v-label">Options</h3>
                            @foreach ($schema['options'] as $option)
                                <div>
                                    @if ($option['accept_value'])
                                        <div class="mb-1 flex items-center gap-2 text-xs">
                                            <span class="font-medium v-strong font-mono">--{{ $option['name'] }}</span>
                                            @if ($option['shortcut'])<span class="text-[10px] v-faint font-mono">-{{ $option['shortcut'] }}</span>@endif
                                        </div>
                                        @if ($option['description'])
                                            <p class="mb-1 text-[10px] v-faint">{{ $option['description'] }}</p>
                                        @endif
                                        <input type="text" wire:model="options.{{ $option['name'] }}" class="v-input">
                                    @else
                                        <label class="flex items-center gap-2 text-xs">
                                            <input type="checkbox" wire:model="options.{{ $option['name'] }}" class="v-checkbox">
                                            <span class="font-medium v-strong font-mono">--{{ $option['name'] }}</span>
                                            @if ($option['description'])<span class="text-[10px] v-faint">{{ $option['description'] }}</span>@endif
                                        </label>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex items-center justify-between border-t pt-3" style="border-color: var(--v-border);">
                        <label class="flex items-center gap-2 text-xs v-muted">
                            <input type="checkbox" wire:model="background" class="v-checkbox">
                            Run in background (queue)
                        </label>
                        <button type="submit" wire:loading.attr="disabled" class="v-btn v-btn--primary">
                            <span wire:loading.remove wire:target="runCommand">Run</span>
                            <span wire:loading wire:target="runCommand">Running…</span>
                        </button>
                    </div>
                </form>
            @endif
        </div>

        @if ($exitCode !== null)
            <div class="v-card overflow-hidden">
                <div class="v-card__header">
                    <h2 class="v-card__title">Output</h2>
                    <span @class(['v-pill', 'is-success' => $exitCode === 0, 'is-danger' => $exitCode !== 0])>exit {{ $exitCode }}</span>
                </div>
                <pre class="overflow-x-auto p-4 text-xs leading-relaxed font-mono" style="background:#0a0a0a; color:#34d399;">{{ $output !== '' ? $output : '(no output)' }}</pre>
            </div>
        @endif
    @endunless
</div>
