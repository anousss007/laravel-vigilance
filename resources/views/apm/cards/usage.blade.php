<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
        <h2 class="text-sm font-semibold">Application usage</h2>
        <div class="flex items-center gap-1" role="group" aria-label="Usage dimension">
            @foreach (['requests' => 'Requests', 'jobs' => 'Jobs'] as $mode => $label)
                <button type="button" wire:click="setUsageMode('{{ $mode }}')" aria-pressed="{{ $usageMode === $mode ? 'true' : 'false' }}"
                        @class(['rounded px-2 py-0.5 text-[11px] transition-colors', 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' => $usageMode === $mode, 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' => $usageMode !== $mode])>{{ $label }}</button>
            @endforeach
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <caption class="sr-only">Top users by {{ $usageMode }}</caption>
            <thead class="text-zinc-500 dark:text-zinc-400"><tr class="border-b border-zinc-100 dark:border-zinc-800">
                <th scope="col" class="px-4 py-2 font-medium">User</th>
                <th scope="col" class="px-4 py-2 text-right font-medium">{{ $usageMode === 'jobs' ? 'Jobs' : 'Requests' }}</th>
                @if ($usageMode === 'requests')<th scope="col" class="px-4 py-2 text-right font-medium">Slow</th>@endif
            </tr></thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($usage as $user)
                    <tr>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                @if (! empty($user['avatar']))
                                    <img src="{{ $user['avatar'] }}" alt="" aria-hidden="true" class="h-6 w-6 shrink-0 rounded-full bg-zinc-200 dark:bg-zinc-800" loading="lazy">
                                @endif
                                <div class="min-w-0">
                                    <div class="truncate font-medium">{{ $user['name'] }}</div>
                                    @if ($user['extra'] !== '')<div class="truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $user['extra'] }}</div>@endif
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($user['count']) }}</td>
                        @if ($usageMode === 'requests')<td class="px-4 py-2 text-right tabular-nums {{ $user['slow'] > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $user['slow'] }}</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-zinc-600 dark:text-zinc-400">No authenticated {{ $usageMode }} recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
