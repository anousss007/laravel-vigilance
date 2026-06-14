@php $fmtMs = fn (int $ms) => $ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s'; @endphp
<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800"><h2 class="text-sm font-semibold">Slow requests</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <caption class="sr-only">Slowest requests by route</caption>
            <thead class="text-zinc-500 dark:text-zinc-400"><tr class="border-b border-zinc-100 dark:border-zinc-800">
                <th scope="col" class="px-4 py-2 font-medium">Route</th>
                <th scope="col" class="px-4 py-2 text-right font-medium">Count</th>
                <th scope="col" class="px-4 py-2 text-right font-medium">Slowest</th>
            </tr></thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($rows as $row)
                    @php $k = json_decode($row->key, true) ?: []; @endphp
                    <tr>
                        <td class="px-4 py-2"><span class="mr-1.5 rounded bg-zinc-100 px-1 py-0.5 font-mono text-[10px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $k[0] ?? '?' }}</span><span class="font-mono">{{ $k[1] ?? $row->key }}</span></td>
                        <td class="px-4 py-2 text-right tabular-nums text-zinc-600 dark:text-zinc-400">{{ (int) $row->count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums font-medium text-amber-700 dark:text-amber-300">{{ $fmtMs((int) $row->max) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-zinc-600 dark:text-zinc-400">No slow requests recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
