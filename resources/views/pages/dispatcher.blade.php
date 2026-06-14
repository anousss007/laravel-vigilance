<div class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Dispatch a job</h1>
            <p class="v-page-sub">Dispatch an allowlisted job with a form reflected from its constructor.</p>
        </div>
    </div>

    @unless ($enabled)
        <div class="v-card v-card--pad text-[13px]" style="border-color: var(--v-warn); background: var(--v-warn-bg); color: var(--v-warn);">
            Manual control is disabled. Set <code class="v-code">vigilance.control.enabled</code> to <code class="v-code">true</code> to dispatch jobs from here.
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div class="v-card v-card--pad">
                    <label class="block">
                        <span class="v-label">Job</span>
                        <select wire:model.live="jobClass" class="v-select">
                            <option value="">Select a job…</option>
                            @foreach ($jobs as $job)
                                <option value="{{ $job['class'] }}">{{ $job['label'] ? $job['label'].' ('.$job['short'].')' : $job['short'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    @if ($jobs === [])
                        <p class="mt-3 text-xs v-muted">No dispatchable jobs are allowed. Configure <code class="v-code">vigilance.control.jobs</code>.</p>
                    @endif

                    @if ($jobClass !== '')
                        <form wire:submit="dispatchJob" class="mt-4 space-y-3 border-t pt-4" style="border-color: var(--v-border);">
                            @forelse ($schema as $field)
                                @php $name = $field['name']; @endphp
                                <div>
                                    <div class="mb-1 flex items-center gap-2 text-xs">
                                        <span class="font-medium v-strong font-mono">{{ $name }}</span>
                                        @if ($field['required'])
                                            <span style="color: var(--v-danger)">*</span>
                                        @endif
                                        <span class="text-[10px] v-faint">
                                            {{ $field['type'] ?? 'mixed' }}@if ($field['is_model']) · model id @endif
                                        </span>
                                    </div>

                                    @if ($field['builtin'] === 'bool')
                                        <label class="inline-flex items-center gap-2 text-xs v-muted">
                                            <input type="checkbox" wire:model="values.{{ $name }}" class="v-checkbox">
                                            <span>true / false</span>
                                        </label>
                                    @elseif ($field['is_enum'])
                                        <select wire:model="values.{{ $name }}" class="v-select">
                                            @unless ($field['required'])
                                                <option value="">— none —</option>
                                            @endunless
                                            @foreach (($field['enum_options'] ?? []) as $value => $caseName)
                                                <option value="{{ $value }}">{{ $caseName }} ({{ $value }})</option>
                                            @endforeach
                                        </select>
                                    @elseif (in_array($field['builtin'], ['int', 'float']) || $field['is_model'])
                                        <input type="number" @if ($field['builtin'] === 'float') step="any" @endif
                                               wire:model="values.{{ $name }}"
                                               placeholder="{{ $field['is_model'] ? 'model id' : '' }}" class="v-input">
                                    @else
                                        <input type="text" wire:model="values.{{ $name }}" class="v-input">
                                    @endif
                                </div>
                            @empty
                                <p class="text-xs v-muted">This job takes no constructor parameters.</p>
                            @endforelse

                            <div class="grid grid-cols-2 gap-3 border-t pt-3" style="border-color: var(--v-border);">
                                <label class="block">
                                    <span class="v-label">Queue</span>
                                    <input type="text" wire:model="queue" placeholder="(default)" class="v-input">
                                </label>
                                <label class="flex items-center gap-2 self-end pb-1.5 text-xs v-muted">
                                    <input type="checkbox" wire:model="sync" class="v-checkbox">
                                    Run synchronously
                                </label>
                            </div>

                            <button type="submit" wire:loading.attr="disabled" class="v-btn v-btn--primary">
                                Dispatch
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Recent manual dispatches --}}
            <div class="v-card">
                <div class="v-card__header"><h2 class="v-card__title">Recent manual dispatches</h2></div>
                <ul>
                    @forelse ($recent as $run)
                        <li class="px-4 py-2.5" style="border-top: 1px solid var(--v-border);">
                            <a href="{{ route('vigilance.runs.show', $run->id) }}" class="block transition-opacity hover:opacity-80">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-xs font-medium v-strong">{{ class_basename($run->name) }}</span>
                                    @include('vigilance::partials.status', ['status' => $run->status])
                                </div>
                                <div class="mt-0.5 text-[10px] v-faint">{{ optional($run->created_at)->diffForHumans() }} · {{ $run->queue ?: 'default' }}</div>
                            </a>
                        </li>
                    @empty
                        <li class="px-4 py-8 text-center text-xs v-muted">Nothing dispatched yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endunless
</div>
