<div @vigilancePoll('30s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Usage</h1>
            <p class="v-page-sub">What Vigilance itself is storing, and the knob that turns each part down.</p>
        </div>
        <x-vigilance::ui.badge tone="neutral">{{ $connection }}</x-vigilance::ui.badge>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-vigilance::ui.card>
            <p class="v-stat__label">Rows stored</p>
            <p class="v-stat__value">{{ number_format($totalRows) }}</p>
            <p class="v-stat__meta">Across every Vigilance table.</p>
        </x-vigilance::ui.card>

        <x-vigilance::ui.card>
            <p class="v-stat__label">Written today</p>
            <p class="v-stat__value">{{ number_format(array_sum(array_map(fn ($t) => $t['last_day'] ?? 0, $tables))) }}</p>
            <p class="v-stat__meta">Rows added in the last 24 hours.</p>
        </x-vigilance::ui.card>

        <x-vigilance::ui.card>
            <p class="v-stat__label">Retention</p>
            <p class="v-stat__value">{{ count($breaches) === 0 ? 'OK' : count($breaches) }}</p>
            <p class="v-stat__meta">{{ count($breaches) === 0 ? 'Pruning is keeping up.' : 'Table(s) holding data past their window.' }}</p>
        </x-vigilance::ui.card>
    </div>

    @if (count($breaches) > 0)
        <x-vigilance::ui.alert class="border-warning/40 bg-warning/10">
            <x-vigilance::ui.alert-title class="text-warning">Pruning is behind</x-vigilance::ui.alert-title>
            <x-vigilance::ui.alert-description>
                <ul class="list-disc ps-5">
                    @foreach ($breaches as $breach)
                        <li>
                            <span class="v-strong">{{ $breach['label'] }}</span> —
                            {{ number_format($breach['stale']) }} row(s) older than its
                            {{ $breach['retention'] }} window.
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2">Trimming runs on a lottery and via <span class="font-mono">vigilance:prune</span>;
                    if this stays non-zero, the scheduled prune is not running.</p>
            </x-vigilance::ui.alert-description>
        </x-vigilance::ui.alert>
    @endif

    <x-vigilance::ui.card class="overflow-hidden">
        <x-vigilance::ui.table>
                <thead>
                    <tr>
                        <th scope="col">Telemetry</th>
                        <th scope="col" class="text-right">Rows</th>
                        <th scope="col" class="text-right">Last 24h</th>
                        <th scope="col" class="text-right">Oldest</th>
                        <th scope="col">Turn it down with</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tables as $table)
                        <tr wire:key="usage-{{ $table['table'] }}">
                            <td>
                                <span class="v-strong">{{ $table['label'] }}</span>
                                <span class="ml-1 font-mono text-xs v-faint">{{ $table['table'] }}</span>
                            </td>
                            <td class="text-right v-num">
                                @if ($table['rows'] === null)
                                    {{-- Not migrated: an optional feature nobody turned on. --}}
                                    <span class="v-faint" title="Table not present">—</span>
                                @else
                                    {{ number_format($table['rows']) }}
                                @endif
                            </td>
                            <td class="text-right v-num v-muted">{{ $table['last_day'] !== null ? number_format($table['last_day']) : '—' }}</td>
                            <td class="text-right v-num v-faint">{{ $table['oldest'] ?? '—' }}</td>
                            <td class="font-mono text-xs v-muted">{{ $table['lever'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-vigilance::ui.table>
    </x-vigilance::ui.card>
</div>
