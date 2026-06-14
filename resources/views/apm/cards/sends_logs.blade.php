@php
    $sendCards = [
        ['title' => 'Mail sent', 'rows' => $mail, 'empty' => 'No mail sent.'],
        ['title' => 'Notifications', 'rows' => $notifications, 'empty' => 'No notifications sent.'],
        ['title' => 'Logs by level', 'rows' => $logs, 'empty' => 'No logs recorded.'],
    ];
@endphp
<div class="grid gap-4 sm:grid-cols-3">
    @foreach ($sendCards as $c)
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800"><h2 class="text-sm font-semibold">{{ $c['title'] }}</h2></div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($c['rows'] as $row)
                    <li class="flex items-center justify-between gap-3 px-4 py-2 text-xs"><span class="min-w-0 flex-1 truncate font-mono text-zinc-700 dark:text-zinc-300">{{ $row->key }}</span><span class="shrink-0 tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format((int) $row->count) }}×</span></li>
                @empty
                    <li class="px-4 py-6 text-center text-zinc-600 dark:text-zinc-400">{{ $c['empty'] }}</li>
                @endforelse
            </ul>
        </div>
    @endforeach
</div>
