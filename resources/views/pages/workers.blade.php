@php
    $statusState = fn (string $s) => match ($s) {
        'running' => 'is-running',
        'paused' => 'is-paused',
        'terminating' => 'is-terminating',
        default => 'is-neutral',
    };
@endphp

<div wire:poll.visible.5s class="space-y-6">
    <div class="v-page-head">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="v-page-title">Workers</h1>
                <span class="v-pill {{ $statusState($control) }} uppercase tracking-wide"><span class="v-dot"></span>{{ $control }}</span>
            </div>
            <p class="v-page-sub">
                Run <code class="v-code">php artisan vigilance:supervise</code> to supervise &amp; auto-scale workers on any driver.
            </p>
        </div>

        <div class="flex items-center gap-2">
            @if ($control === 'paused')
                <button type="button" wire:click="resume" class="v-btn v-btn--primary v-btn--sm">Resume</button>
            @else
                <button type="button" wire:click="pause" class="v-btn v-btn--sm">Pause</button>
            @endif
            <button type="button" wire:click="restart" class="v-btn v-btn--sm">Restart</button>
        </div>
    </div>

    @forelse ($supervisors as $supervisor)
        <div class="v-card overflow-hidden">
            <div class="v-card__header">
                <div class="flex items-center gap-2.5">
                    <h2 class="v-card__title">{{ $supervisor->name }}</h2>
                    <span class="text-[11px] uppercase tracking-wide v-faint font-mono">{{ $supervisor->connection }} · {{ $supervisor->queues }} · {{ $supervisor->balance }}</span>
                </div>
                <div class="flex items-center gap-3 text-xs">
                    <span class="v-pill {{ $statusState($supervisor->status) }} uppercase tracking-wide"><span class="v-dot"></span>{{ $supervisor->status }}</span>
                    <span class="font-semibold v-strong v-num">{{ $supervisor->processes }} worker(s)</span>
                    @if ($supervisor->last_heartbeat_at)
                        <span class="v-faint">beat {{ $supervisor->last_heartbeat_at->diffForHumans() }}</span>
                    @endif
                </div>
            </div>

            @php $rows = $workers[$supervisor->name] ?? collect(); @endphp
            @if ($rows->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="v-table v-table--hover">
                        <thead>
                            <tr>
                                <th scope="col">PID</th>
                                <th scope="col">Queue</th>
                                <th scope="col">Connection</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $worker)
                                <tr>
                                    <td class="font-mono v-num v-strong">{{ $worker->pid }}</td>
                                    <td class="font-mono">{{ $worker->queue }}</td>
                                    <td class="font-mono v-muted">{{ $worker->connection }}</td>
                                    <td class="v-muted">{{ $worker->status }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="px-4 py-3 text-xs v-muted">No worker processes running (idle or paused).</p>
            @endif
        </div>
    @empty
        <div class="v-empty">
            <p class="v-empty__title">Vigilance isn&rsquo;t supervising any workers.</p>
            <p class="mx-auto mt-1 max-w-md">
                This is optional. To let Vigilance run and auto-scale your queue workers (a
                driver-agnostic alternative to Horizon), start
                <code class="v-code">php artisan vigilance:supervise</code>.
                If you already use Horizon or a <code class="v-code">sync</code>/external
                worker, you can safely ignore this page.
            </p>
        </div>
    @endforelse
</div>
