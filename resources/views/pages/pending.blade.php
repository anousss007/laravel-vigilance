<div wire:poll.visible.5s class="space-y-4">
    <div class="flex items-baseline justify-between">
        <h1 class="text-base font-semibold">Pending jobs</h1>
        <span class="text-xs text-zinc-600 dark:text-zinc-400">live queue backend</span>
    </div>

    <p class="text-xs text-zinc-600 dark:text-zinc-400">Jobs currently waiting in the queue backend. Browsable for the <code>database</code> driver; other drivers show a note.</p>

    @forelse ($groups as $group)
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-baseline justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="font-semibold">{{ $group['connection'] }}</h2>
                <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">{{ $group['driver'] }}</span>
            </div>

            @if ($group['jobs'] === null)
                <p class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-400">Live browsing isn't available for the <code>{{ $group['driver'] }}</code> driver — check the Runs page for captured queued jobs.</p>
            @elseif ($group['jobs'] === [])
                <p class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-400">No pending jobs — the queue is empty.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-medium">ID</th>
                                <th scope="col" class="px-4 py-2 font-medium">Queue</th>
                                <th scope="col" class="px-4 py-2 font-medium">Job</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Attempts</th>
                                <th scope="col" class="px-4 py-2 font-medium">State</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['jobs'] as $job)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-4 py-2">{{ $job['id'] }}</td>
                                    <td class="px-4 py-2">{{ $job['queue'] }}</td>
                                    <td class="px-4 py-2 font-medium">{{ class_basename($job['name']) }}</td>
                                    <td class="px-4 py-2 text-right">{{ $job['attempts'] }}</td>
                                    <td class="px-4 py-2">
                                        @if ($job['reserved'])
                                            <span class="text-blue-700 dark:text-blue-300">reserved</span>
                                        @elseif ($job['delayed'])
                                            <span class="text-amber-700 dark:text-amber-300">delayed</span>
                                        @else
                                            <span class="text-emerald-700 dark:text-emerald-300">ready</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-lg border border-zinc-200 bg-white p-10 text-center text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
            No queue connections seen in the last 24 hours.
        </div>
    @endforelse
</div>
