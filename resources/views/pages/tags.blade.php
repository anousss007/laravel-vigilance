<div wire:poll.visible.10s class="space-y-4">
    <div class="flex items-baseline justify-between">
        <h1 class="text-base font-semibold">Tags</h1>
        <span class="text-xs text-zinc-600 dark:text-zinc-400">last 7 days</span>
    </div>

    @if ($monitored->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Watching</span>
            @foreach ($monitored as $t)
                <a href="{{ route('vigilance.runs', ['tag' => $t]) }}" class="rounded border border-emerald-500/40 bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-700 hover:bg-emerald-500/20 dark:text-emerald-300">{{ $t }}</a>
            @endforeach
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="w-full text-left text-xs">
            <thead class="border-b border-zinc-200 text-[10px] uppercase tracking-wide text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
                <tr>
                    <th scope="col" class="px-4 py-2 font-medium">Tag</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Runs (7d)</th>
                    <th scope="col" class="px-4 py-2 font-medium">Last seen</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($tags as $tag)
                    <tr wire:key="tag-{{ md5($tag->tag) }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-4 py-2">
                            <a href="{{ route('vigilance.runs', ['tag' => $tag->tag]) }}" class="font-medium hover:underline">{{ $tag->tag }}</a>
                        </td>
                        <td class="px-4 py-2 text-right">{{ $tag->runs }}</td>
                        <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ \Illuminate\Support\Carbon::parse($tag->last_seen)->diffForHumans() }}</td>
                        <td class="px-4 py-2 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('vigilance.runs', ['tag' => $tag->tag]) }}" class="rounded border border-zinc-300 px-2 py-1 text-[10px] text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">Runs</a>
                                @if ($monitored->contains($tag->tag))
                                    <button type="button" wire:click="unmonitor(@js($tag->tag))" class="rounded border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 text-[10px] text-emerald-700 hover:bg-emerald-500/20 dark:text-emerald-300">Watching</button>
                                @else
                                    <button type="button" wire:click="monitor(@js($tag->tag))" class="rounded border border-zinc-300 px-2 py-1 text-[10px] text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">Watch</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-zinc-600 dark:text-zinc-400">No tags seen in the last 7 days.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
