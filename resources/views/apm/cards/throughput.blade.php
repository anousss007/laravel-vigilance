<div class="v-card overflow-hidden">
    <div class="v-card__header"><h2 class="v-card__title">Job throughput</h2></div>
    <div class="overflow-x-auto">
        <table class="v-table v-table--hover">
            <caption class="sr-only">Jobs processed, released and failed per queue</caption>
            <thead>
                <tr>
                    <th scope="col">Queue</th>
                    <th scope="col" class="text-right">Processed</th>
                    <th scope="col" class="text-right">Released</th>
                    <th scope="col" class="text-right">Failed</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="font-mono">{{ $row['queue'] }}</td>
                        <td class="text-right v-num" style="color: var(--v-success);">{{ number_format($row['processed']) }}</td>
                        <td class="text-right v-num v-muted">{{ number_format($row['released']) }}</td>
                        <td class="text-right v-num" @if ($row['failed'] > 0) style="color: var(--v-danger);" @else style="color: var(--v-faint);" @endif>{{ number_format($row['failed']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center v-muted">No job throughput recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
