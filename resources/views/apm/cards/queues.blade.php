<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
        <h2 class="text-sm font-semibold">Queues</h2>
        <a href="{{ route('vigilance.workload') }}" class="text-xs text-emerald-700 hover:underline dark:text-emerald-300">details</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <caption class="sr-only">Active queues, backlog and throughput</caption>
            <thead class="text-zinc-500 dark:text-zinc-400"><tr class="border-b border-zinc-100 dark:border-zinc-800">
                <th scope="col" class="px-4 py-2 font-medium">Queue</th>
                <th scope="col" class="px-4 py-2 text-right font-medium">Depth</th>
                <th scope="col" class="px-4 py-2 text-right font-medium">Workers</th>
                <th scope="col" class="px-4 py-2 text-right font-medium">Processed/h</th>
            </tr></thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($queues as $queue)
                    <tr>
                        <td class="px-4 py-2 font-mono">{{ $queue['queue'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $queue['depth'] ?? '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $queue['workers'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-zinc-600 dark:text-zinc-400">{{ $queue['processed_last_hour'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-zinc-600 dark:text-zinc-400">No active queues.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
