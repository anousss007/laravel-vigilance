<div @vigilancePoll('5s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Pending jobs</h1>
            <p class="v-page-sub">Jobs currently waiting in the queue backend. Browsable for the <code class="v-code">database</code> driver; other drivers show a note.</p>
        </div>
        <span class="text-xs v-muted">live queue backend</span>
    </div>

    @forelse ($groups as $group)
        @php $canCancel = $controlEnabled && $group['driver'] === 'database' && ! empty($group['jobs']); @endphp
        <x-vigilance::ui.card variant="sectioned" class="overflow-hidden">
            <x-vigilance::ui.card-header>
                <div class="flex items-center gap-2.5">
                    <x-vigilance::ui.card-title>{{ $group['connection'] }}</x-vigilance::ui.card-title>
                    <x-vigilance::ui.badge tone="neutral" class="font-mono">{{ $group['driver'] }}</x-vigilance::ui.badge>
                </div>
                @if ($canCancel)
                    {{-- card-action pins this to the header's right-hand cell;
                         without it the button lands on its own grid row and
                         stretches the full width of the card. --}}
                    <x-vigilance::ui.card-action>
                        <x-vigilance::ui.button variant="destructive" size="sm" wire:click="cancelSelected(@js($group['connection']))" wire:confirm="Cancel the selected pending job(s)? This deletes them from the queue and cannot be undone.">Cancel selected</x-vigilance::ui.button>
                    </x-vigilance::ui.card-action>
                @endif
            </x-vigilance::ui.card-header>

            @if ($group['jobs'] === null)
                <p class="px-4 py-3 text-xs v-muted">Live browsing isn't available for the <code class="v-code">{{ $group['driver'] }}</code> driver — check the Runs page for captured queued jobs.</p>
            @elseif ($group['jobs'] === [])
                <p class="px-4 py-3 text-xs v-muted">No pending jobs — the queue is empty.</p>
            @else
                <x-vigilance::ui.table>
                        <thead>
                            <tr>
                                @if ($canCancel)<th scope="col" class="w-8"><span class="sr-only">Select</span></th>@endif
                                <th scope="col">ID</th>
                                <th scope="col">Queue</th>
                                <th scope="col">Job</th>
                                <th scope="col" class="text-right">Attempts</th>
                                <th scope="col">State</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['jobs'] as $job)
                                <tr>
                                    @if ($canCancel)
                                        <td><input type="checkbox" wire:model="selected" value="{{ $group['connection'].'#'.$job['id'] }}" aria-label="Select job {{ $job['id'] }}"></td>
                                    @endif
                                    <td class="font-mono v-num">{{ $job['id'] }}</td>
                                    <td class="font-mono">{{ $job['queue'] }}</td>
                                    <td class="font-medium v-strong">{{ class_basename($job['name']) }}</td>
                                    <td class="text-right v-num">{{ $job['attempts'] }}</td>
                                    <td>
                                        @if ($job['reserved'])
                                            <x-vigilance::ui.badge tone="info"><span class="v-dot"></span>reserved</x-vigilance::ui.badge>
                                        @elseif ($job['delayed'])
                                            <x-vigilance::ui.badge tone="warning"><span class="v-dot"></span>delayed</x-vigilance::ui.badge>
                                        @else
                                            <x-vigilance::ui.badge tone="success"><span class="v-dot"></span>ready</x-vigilance::ui.badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-vigilance::ui.table>
            @endif
        </x-vigilance::ui.card>
    @empty
        <x-vigilance::ui.empty>
    <x-vigilance::ui.empty-title>No queue connections seen in the last 24 hours.</x-vigilance::ui.empty-title>
</x-vigilance::ui.empty>
    @endforelse
</div>
