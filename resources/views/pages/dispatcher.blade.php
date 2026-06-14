<div class="space-y-6">
    <h1 class="text-base font-semibold">Dispatch a job</h1>

    @unless ($enabled)
        <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-xs text-amber-700 dark:text-amber-300">
            Manual control is disabled. Set <code class="rounded bg-amber-500/20 px-1">vigilance.control.enabled</code> to <code class="rounded bg-amber-500/20 px-1">true</code> to dispatch jobs from here.
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <label class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Job</span>
                        <select wire:model.live="jobClass" class="rounded border border-zinc-300 bg-transparent px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900">
                            <option value="">Select a job…</option>
                            @foreach ($jobs as $job)
                                <option value="{{ $job['class'] }}">{{ $job['label'] ? $job['label'].' ('.$job['short'].')' : $job['short'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    @if ($jobs === [])
                        <p class="mt-3 text-xs text-zinc-600 dark:text-zinc-400">No dispatchable jobs are allowed. Configure <code>vigilance.control.jobs</code>.</p>
                    @endif

                    @if ($jobClass !== '')
                        <form wire:submit="dispatchJob" class="mt-4 space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                            @forelse ($schema as $field)
                                @php $name = $field['name']; @endphp
                                <div>
                                    <div class="mb-1 flex items-center gap-2 text-xs">
                                        <span class="font-medium">{{ $name }}</span>
                                        @if ($field['required'])
                                            <span class="text-red-500">*</span>
                                        @endif
                                        <span class="text-[10px] text-zinc-600 dark:text-zinc-400">
                                            {{ $field['type'] ?? 'mixed' }}@if ($field['is_model']) · model id @endif
                                        </span>
                                    </div>

                                    @if ($field['builtin'] === 'bool')
                                        <label class="inline-flex items-center gap-2 text-xs">
                                            <input type="checkbox" wire:model="values.{{ $name }}" class="rounded border-zinc-400 text-emerald-500 focus:ring-emerald-500">
                                            <span class="text-zinc-600 dark:text-zinc-400">true / false</span>
                                        </label>
                                    @elseif ($field['is_enum'])
                                        <select wire:model="values.{{ $name }}" class="w-full rounded border border-zinc-300 bg-transparent px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900">
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
                                               placeholder="{{ $field['is_model'] ? 'model id' : '' }}"
                                               class="w-full rounded border border-zinc-300 bg-transparent px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700">
                                    @else
                                        <input type="text" wire:model="values.{{ $name }}"
                                               class="w-full rounded border border-zinc-300 bg-transparent px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700">
                                    @endif
                                </div>
                            @empty
                                <p class="text-xs text-zinc-600 dark:text-zinc-400">This job takes no constructor parameters.</p>
                            @endforelse

                            <div class="grid grid-cols-2 gap-3 border-t border-zinc-200 pt-3 dark:border-zinc-800">
                                <label class="flex flex-col gap-1">
                                    <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Queue</span>
                                    <input type="text" wire:model="queue" placeholder="(default)"
                                           class="rounded border border-zinc-300 bg-transparent px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none dark:border-zinc-700">
                                </label>
                                <label class="flex items-center gap-2 self-end pb-1.5 text-xs">
                                    <input type="checkbox" wire:model="sync" class="rounded border-zinc-400 text-emerald-500 focus:ring-emerald-500">
                                    Run synchronously
                                </label>
                            </div>

                            <button type="submit" wire:loading.attr="disabled"
                                    class="rounded bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-zinc-950 hover:bg-emerald-400 disabled:opacity-50">
                                Dispatch
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Recent manual dispatches --}}
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800"><h2 class="text-sm font-semibold">Recent manual dispatches</h2></div>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($recent as $run)
                        <li class="px-4 py-2.5">
                            <a href="{{ route('vigilance.runs.show', $run->id) }}" class="block hover:opacity-80">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-xs font-medium">{{ class_basename($run->name) }}</span>
                                    @include('vigilance::partials.status', ['status' => $run->status])
                                </div>
                                <div class="mt-0.5 text-[10px] text-zinc-600 dark:text-zinc-400">{{ optional($run->created_at)->diffForHumans() }} · {{ $run->queue ?: 'default' }}</div>
                            </a>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-xs text-zinc-600 dark:text-zinc-400">Nothing dispatched yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endunless
</div>
