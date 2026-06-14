<div class="v-card overflow-hidden">
    <div class="v-card__header">
        <h2 class="v-card__title">Queues</h2>
        <a href="{{ route('vigilance.workload') }}" class="text-xs v-link">details</a>
    </div>
    <div class="overflow-x-auto">
        <table class="v-table v-table--hover">
            <caption class="sr-only">Active queues, backlog and throughput</caption>
            <thead>
                <tr>
                    <th scope="col">Queue</th>
                    <th scope="col" class="text-right">Depth</th>
                    <th scope="col" class="text-right">Workers</th>
                    <th scope="col" class="text-right">Processed/h</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($queues as $queue)
                    <tr>
                        <td class="font-mono">{{ $queue['queue'] }}</td>
                        <td class="text-right v-num">{{ $queue['depth'] ?? '—' }}</td>
                        <td class="text-right v-num">{{ $queue['workers'] }}</td>
                        <td class="text-right v-num v-muted">{{ $queue['processed_last_hour'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center v-muted">No active queues.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
