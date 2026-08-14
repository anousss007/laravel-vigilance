@php
    $fmtDuration = function (int $s): string {
        if ($s < 60) {
            return $s.'s';
        }
        if ($s < 3600) {
            return floor($s / 60).'m';
        }
        if ($s < 86400) {
            return floor($s / 3600).'h '.floor(($s % 3600) / 60).'m';
        }
        return floor($s / 86400).'d '.floor(($s % 86400) / 3600).'h';
    };
    $levelPill = fn (string $l) => match ($l) {
        'critical' => 'is-danger',
        'warning' => 'is-warn',
        default => 'is-info',
    };
@endphp

<div @vigilancePoll('15s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Incidents</h1>
            <p class="v-page-sub"><span class="v-num">{{ $openCount }}</span> open · auto-resolve when the alert clears</p>
        </div>
    </div>

    <x-vigilance::ui.card>
        <div class="flex gap-1">
            @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All'] as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')"
                        @class(['v-btn v-btn--sm', 'v-btn--primary' => $tab === $key, 'v-btn--ghost' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>
    </x-vigilance::ui.card>

    <x-vigilance::ui.card class="overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <x-vigilance::ui.table>
                <thead>
                    <tr>
                        <th scope="col">Incident</th>
                        <th scope="col">Level</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Count</th>
                        <th scope="col">Opened</th>
                        <th scope="col" class="text-right">Duration</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($incidents as $incident)
                        <tr wire:key="incident-{{ $incident->id }}">
                            <td>
                                <span class="font-medium v-strong">{{ $incident->title }}</span>
                                @if ($incident->message)
                                    <div class="max-w-md truncate text-[11px] v-faint" title="{{ $incident->message }}">{{ $incident->message }}</div>
                                @endif
                            </td>
                            <td><span @class(['v-pill', $levelPill($incident->level)])>{{ $incident->level }}</span></td>
                            <td>
                                <span @class(['v-pill', 'is-danger' => ! $incident->isResolved(), 'is-success' => $incident->isResolved()])>
                                    <span class="v-dot"></span>{{ $incident->status }}
                                </span>
                            </td>
                            <td class="text-right v-num">{{ $incident->occurrences }}</td>
                            <td class="v-muted" title="{{ $incident->opened_at }}">{{ optional($incident->opened_at)->diffForHumans() ?? '—' }}</td>
                            <td class="text-right v-num v-muted">{{ $fmtDuration($incident->durationSeconds()) }}</td>
                            <td class="text-right">
                                @unless ($incident->isResolved())
                                    <button type="button" wire:click="resolve({{ $incident->id }})" class="v-btn v-btn--sm v-btn--primary">Resolve</button>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">
                            <x-vigilance::ui.empty>
    <x-vigilance::ui.empty-title>No incidents here.</x-vigilance::ui.empty-title>
    <x-vigilance::ui.empty-description><p>Incidents open automatically when an alert rule fires.</p></x-vigilance::ui.empty-description>
</x-vigilance::ui.empty>
                        </td></tr>
                    @endforelse
                </tbody>
            </x-vigilance::ui.table>
        </div>
    </x-vigilance::ui.card>

    <div>{{ $incidents->links('vigilance::pagination') }}</div>
</div>
