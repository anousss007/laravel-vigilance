<div wire:poll.visible.5s class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Pending jobs</h1>
            <p class="v-page-sub">Jobs currently waiting in the queue backend. Browsable for the <code class="v-code">database</code> driver; other drivers show a note.</p>
        </div>
        <span class="text-xs v-muted">live queue backend</span>
    </div>

    @forelse ($groups as $group)
        <div class="v-card overflow-hidden">
            <div class="v-card__header">
                <h2 class="v-card__title">{{ $group['connection'] }}</h2>
                <span class="v-pill is-neutral font-mono">{{ $group['driver'] }}</span>
            </div>

            @if ($group['jobs'] === null)
                <p class="px-4 py-3 text-xs v-muted">Live browsing isn't available for the <code class="v-code">{{ $group['driver'] }}</code> driver — check the Runs page for captured queued jobs.</p>
            @elseif ($group['jobs'] === [])
                <p class="px-4 py-3 text-xs v-muted">No pending jobs — the queue is empty.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="v-table v-table--hover">
                        <thead>
                            <tr>
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
                                    <td class="font-mono v-num">{{ $job['id'] }}</td>
                                    <td class="font-mono">{{ $job['queue'] }}</td>
                                    <td class="font-medium v-strong">{{ class_basename($job['name']) }}</td>
                                    <td class="text-right v-num">{{ $job['attempts'] }}</td>
                                    <td>
                                        @if ($job['reserved'])
                                            <span class="v-pill is-info"><span class="v-dot"></span>reserved</span>
                                        @elseif ($job['delayed'])
                                            <span class="v-pill is-warn"><span class="v-dot"></span>delayed</span>
                                        @else
                                            <span class="v-pill is-success"><span class="v-dot"></span>ready</span>
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
        <div class="v-empty">
            <p class="v-empty__title">No queue connections seen in the last 24 hours.</p>
        </div>
    @endforelse
</div>
