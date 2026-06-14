@php $fmtMs = fn (int $ms) => $ms < 1000 ? $ms.'ms' : number_format($ms / 1000, 2).'s'; @endphp
<div class="v-card overflow-hidden">
    <div class="v-card__header"><h2 class="v-card__title">Slow requests</h2></div>
    <div class="overflow-x-auto">
        <table class="v-table v-table--hover">
            <caption class="sr-only">Slowest requests by route</caption>
            <thead>
                <tr>
                    <th scope="col">Route</th>
                    <th scope="col" class="text-right">Count</th>
                    <th scope="col" class="text-right">Slowest</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php $k = json_decode($row->key, true) ?: []; @endphp
                    <tr>
                        <td><code class="v-code mr-1.5">{{ $k[0] ?? '?' }}</code><span class="font-mono">{{ $k[1] ?? $row->key }}</span></td>
                        <td class="text-right v-num v-muted">{{ (int) $row->count }}</td>
                        <td class="text-right v-num font-medium" style="color: var(--v-warn);">{{ $fmtMs((int) $row->max) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center v-muted">No slow requests recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
