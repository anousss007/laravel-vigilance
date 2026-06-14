@php
    $sendCards = [
        ['title' => 'Mail sent', 'rows' => $mail, 'empty' => 'No mail sent.'],
        ['title' => 'Notifications', 'rows' => $notifications, 'empty' => 'No notifications sent.'],
        ['title' => 'Logs by level', 'rows' => $logs, 'empty' => 'No logs recorded.'],
    ];
@endphp
<div class="grid gap-4 sm:grid-cols-3">
    @foreach ($sendCards as $c)
        <div class="v-card overflow-hidden">
            <div class="v-card__header"><h2 class="v-card__title">{{ $c['title'] }}</h2></div>
            <ul>
                @forelse ($c['rows'] as $row)
                    <li class="flex items-center justify-between gap-3 px-4 py-2 text-xs" style="border-top: 1px solid var(--v-border);"><span class="min-w-0 flex-1 truncate font-mono v-strong">{{ $row->key }}</span><span class="shrink-0 v-num v-faint">{{ number_format((int) $row->count) }}×</span></li>
                @empty
                    <li class="px-4 py-8 text-center v-muted">{{ $c['empty'] }}</li>
                @endforelse
            </ul>
        </div>
    @endforeach
</div>
