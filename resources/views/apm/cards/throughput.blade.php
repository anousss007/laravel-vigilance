<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800"><h2 class="text-sm font-semibold">Job throughput</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <caption class="sr-only">Jobs processed, released and failed per queue</caption>
            <thead class="text-zinc-500 dark:text-zinc-400"><tr class="border-b border-zinc-100 dark:border-zinc-800">
                <th scope="col" class="px-4 py-2 font-medium">Queue</th>
                <th scope="col" class="px-4 py-2 text-right font-medium">Processed</th>
                <th scope="col" class="px-4 py-2 text-right font-medium">Released</th>
                <th scope="col" class="px-4 py-2 text-right font-medium">Failed</th>
            </tr></thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($rows as $row)
                    <tr>
                        <td class="px-4 py-2 font-mono">{{ $row['queue'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-emerald-700 dark:text-emerald-300">{{ number_format($row['processed']) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-zinc-600 dark:text-zinc-400">{{ number_format($row['released']) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums {{ $row['failed'] > 0 ? 'text-red-700 dark:text-red-300' : 'text-zinc-500 dark:text-zinc-400' }}">{{ number_format($row['failed']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-zinc-600 dark:text-zinc-400">No job throughput recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
