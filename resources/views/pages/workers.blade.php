@php
    $statusClasses = fn (string $s) => match ($s) {
        'running' => 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        'paused' => 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300',
        'terminating' => 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300',
        default => 'border-slate-500/40 bg-slate-500/10 text-slate-700 dark:text-slate-300',
    };
@endphp

<div wire:poll.visible.5s class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <h1 class="text-base font-semibold">Workers</h1>
            <span class="inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide {{ $statusClasses($control) }}">{{ $control }}</span>
        </div>

        <div class="flex items-center gap-2">
            @if ($control === 'paused')
                <button type="button" wire:click="resume"
                        class="rounded bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-zinc-950 hover:bg-emerald-400">
                    Resume
                </button>
            @else
                <button type="button" wire:click="pause"
                        class="rounded border border-zinc-300 px-3 py-1.5 text-xs text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    Pause
                </button>
            @endif

            <button type="button" wire:click="restart"
                    class="rounded border border-zinc-300 px-3 py-1.5 text-xs text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                Restart
            </button>
        </div>
    </div>

    <p class="text-xs text-zinc-600 dark:text-zinc-400">
        Run <code>php artisan vigilance:supervise</code> to start supervising your queue workers — it auto-scales them across all drivers.
    </p>

    @forelse ($supervisors as $supervisor)
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <h2 class="font-semibold">{{ $supervisor->name }}</h2>
                    <span class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">{{ $supervisor->connection }} · {{ $supervisor->queues }} · {{ $supervisor->balance }}</span>
                </div>
                <div class="flex items-center gap-3 text-xs">
                    <span class="inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide {{ $statusClasses($supervisor->status) }}">{{ $supervisor->status }}</span>
                    <span class="font-semibold">{{ $supervisor->processes }} worker(s)</span>
                    @if ($supervisor->last_heartbeat_at)
                        <span class="text-zinc-600 dark:text-zinc-400">beat {{ $supervisor->last_heartbeat_at->diffForHumans() }}</span>
                    @endif
                </div>
            </div>

            @php $rows = $workers[$supervisor->name] ?? collect(); @endphp
            @if ($rows->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="text-[10px] uppercase tracking-wide text-zinc-600 dark:text-zinc-400">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-medium">PID</th>
                                <th scope="col" class="px-4 py-2 font-medium">Queue</th>
                                <th scope="col" class="px-4 py-2 font-medium">Connection</th>
                                <th scope="col" class="px-4 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $worker)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-4 py-2 font-medium">{{ $worker->pid }}</td>
                                    <td class="px-4 py-2">{{ $worker->queue }}</td>
                                    <td class="px-4 py-2">{{ $worker->connection }}</td>
                                    <td class="px-4 py-2">{{ $worker->status }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-400">No worker processes running (idle or paused).</p>
            @endif
        </div>
    @empty
        <div class="rounded-lg border border-zinc-200 bg-white p-10 text-center text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
            No supervisors are running. Start one with <code>php artisan vigilance:supervise</code>.
        </div>
    @endforelse
</div>
