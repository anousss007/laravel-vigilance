<div @vigilancePoll('10s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Tags</h1>
            <p class="v-page-sub">last 7 days</p>
        </div>
    </div>

    @if ($monitored->isNotEmpty())
        <x-vigilance::ui.card class="flex flex-wrap items-center gap-2">
            <span class="v-label mb-0">Watching</span>
            @foreach ($monitored as $t)
                <a href="{{ route('vigilance.runs', ['tag' => $t]) }}" class="v-pill is-success font-mono hover:underline">{{ $t }}</a>
            @endforeach
        </x-vigilance::ui.card>
    @endif

    <x-vigilance::ui.card class="overflow-hidden">
        <x-vigilance::ui.table>
                <thead>
                    <tr>
                        <th scope="col">Tag</th>
                        <th scope="col" class="text-right">Runs (7d)</th>
                        <th scope="col">Last seen</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tags as $tag)
                        <tr wire:key="tag-{{ md5($tag->tag) }}">
                            <td>
                                <a href="{{ route('vigilance.runs', ['tag' => $tag->tag]) }}" class="font-medium font-mono v-strong hover:underline">{{ $tag->tag }}</a>
                            </td>
                            <td class="text-right v-num">{{ $tag->runs }}</td>
                            <td class="v-muted">{{ \Illuminate\Support\Carbon::parse($tag->last_seen)->diffForHumans() }}</td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('vigilance.runs', ['tag' => $tag->tag]) }}" class="v-btn v-btn--sm">Runs</a>
                                    @if ($monitored->contains($tag->tag))
                                        <button type="button" wire:click="unmonitor(@js($tag->tag))" class="v-btn v-btn--sm v-btn--primary">Watching</button>
                                    @else
                                        <button type="button" wire:click="monitor(@js($tag->tag))" class="v-btn v-btn--sm">Watch</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">
                            <x-vigilance::ui.empty>
    <x-vigilance::ui.empty-title>No tags seen in the last 7 days.</x-vigilance::ui.empty-title>
</x-vigilance::ui.empty>
                        </td></tr>
                    @endforelse
                </tbody>
            </x-vigilance::ui.table>
    </x-vigilance::ui.card>
</div>
