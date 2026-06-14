<div class="v-card overflow-hidden">
    <div class="v-card__header">
        <h2 class="v-card__title">Application usage</h2>
        <div class="flex items-center gap-1" role="group" aria-label="Usage dimension">
            @foreach (['requests' => 'Requests', 'jobs' => 'Jobs'] as $mode => $label)
                <button type="button" wire:click="setUsageMode('{{ $mode }}')" aria-pressed="{{ $usageMode === $mode ? 'true' : 'false' }}"
                        @class(['v-btn v-btn--sm', 'v-btn--primary' => $usageMode === $mode, 'v-btn--ghost' => $usageMode !== $mode])>{{ $label }}</button>
            @endforeach
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="v-table v-table--hover">
            <caption class="sr-only">Top users by {{ $usageMode }}</caption>
            <thead>
                <tr>
                    <th scope="col">User</th>
                    <th scope="col" class="text-right">{{ $usageMode === 'jobs' ? 'Jobs' : 'Requests' }}</th>
                    @if ($usageMode === 'requests')<th scope="col" class="text-right">Slow</th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($usage as $user)
                    <tr>
                        <td>
                            <div class="flex items-center gap-2">
                                @if (! empty($user['avatar']))
                                    <img src="{{ $user['avatar'] }}" alt="" aria-hidden="true" class="h-6 w-6 shrink-0 rounded-full" style="background: var(--v-surface-2);" loading="lazy">
                                @endif
                                <div class="min-w-0">
                                    <div class="truncate font-medium v-strong">{{ $user['name'] }}</div>
                                    @if ($user['extra'] !== '')<div class="truncate text-[11px] v-faint">{{ $user['extra'] }}</div>@endif
                                </div>
                            </div>
                        </td>
                        <td class="text-right v-num">{{ number_format($user['count']) }}</td>
                        @if ($usageMode === 'requests')<td class="text-right v-num" @if ($user['slow'] > 0) style="color: var(--v-warn);" @else style="color: var(--v-faint);" @endif>{{ $user['slow'] }}</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center v-muted">No authenticated {{ $usageMode }} recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
