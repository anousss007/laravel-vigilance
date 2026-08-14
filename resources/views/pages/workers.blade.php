@php
    $statusState = fn (string $s) => match ($s) {
        'running' => 'is-running',
        'paused' => 'is-paused',
        'terminating' => 'is-terminating',
        default => 'is-neutral',
    };
@endphp

<div @vigilancePoll('5s') class="space-y-6">
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
                <x-vigilance::ui.button size="sm" wire:click="resume">Resume</x-vigilance::ui.button>
            @else
                <x-vigilance::ui.button variant="outline" size="sm" wire:click="pause">Pause</x-vigilance::ui.button>
            @endif
            <x-vigilance::ui.button variant="outline" size="sm" wire:click="restart">Restart</x-vigilance::ui.button>
        </div>
    </div>

    @include('vigilance::partials.fleet-chart', [
        'series' => $fleetSeries,
        'ranges' => $this->ranges(),
        'labels' => $this->rangeLabels(),
        'current' => $range,
    ])

    @forelse ($supervisors as $supervisor)
        <x-vigilance::ui.card variant="sectioned" class="overflow-hidden">
            <x-vigilance::ui.card-header>
                <div class="flex min-w-0 flex-wrap items-center gap-2.5">
                    <x-vigilance::ui.card-title>{{ $supervisor->name }}</x-vigilance::ui.card-title>
                    @if ($supervisor->host)
                        <x-vigilance::ui.badge tone="neutral" class="uppercase tracking-wide font-mono" title="node">{{ $supervisor->host }}</x-vigilance::ui.badge>
                    @endif
                    <span class="text-[11px] uppercase tracking-wide v-faint font-mono">{{ $supervisor->connection }} · {{ $supervisor->queues }} · {{ $supervisor->balance }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-xs">
                    <span class="v-pill {{ $statusState($supervisor->status) }} uppercase tracking-wide"><span class="v-dot"></span>{{ $supervisor->status }}</span>
                    <span class="font-semibold v-strong v-num">{{ $supervisor->processes }} worker(s)</span>
                    @if ($supervisor->last_heartbeat_at)
                        <span class="v-faint">beat {{ $supervisor->last_heartbeat_at->diffForHumans() }}</span>
                    @endif
                </div>
            </x-vigilance::ui.card-header>

            @php $rows = $workers[$supervisor->name.'@'.$supervisor->host] ?? collect(); @endphp
            @if ($rows->isNotEmpty())
                <x-vigilance::ui.table>
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
                    </x-vigilance::ui.table>
            @else
                <p class="px-4 py-3 text-xs v-muted">No worker processes running (idle or paused).</p>
            @endif
        </x-vigilance::ui.card>
    @empty
        <x-vigilance::ui.empty>
    <x-vigilance::ui.empty-title>Vigilance isn&rsquo;t supervising any workers.</x-vigilance::ui.empty-title>
    <x-vigilance::ui.empty-description><p class="mx-auto mt-1 max-w-md">
                This is optional. To let Vigilance run and auto-scale your queue workers (a
                driver-agnostic alternative to Horizon), start
                <code class="v-code">php artisan vigilance:supervise</code>.
                If you already use Horizon or a <code class="v-code">sync</code>/external
                worker, you can safely ignore this page.
            </p></x-vigilance::ui.empty-description>
</x-vigilance::ui.empty>
    @endforelse
</div>
