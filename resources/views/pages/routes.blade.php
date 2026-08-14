<div @vigilancePoll('10s') class="space-y-6">
    <div class="v-page-head">
        <div>
            <h1 class="v-page-title">Routes</h1>
            <p class="v-page-sub">Per-route throughput, error rate, Apdex and latency percentiles — plus what each page costs in queries, database time, memory and hydrated models.</p>
        </div>
    </div>

    <x-vigilance::ui.card class="p-2">
        <x-vigilance::range-picker :ranges="$this->ranges()" :labels="$this->rangeLabels()" :current="$range" />
    </x-vigilance::ui.card>

    <x-vigilance::ui.card class="overflow-hidden">
        <x-vigilance::ui.table>
                <thead>
                    <tr>
                        <th scope="col">Route</th>
                        <th scope="col" class="text-right">Reqs</th>
                        <th scope="col" class="text-right">Err %</th>
                        <th scope="col" class="text-right">Apdex</th>
                        <th scope="col" class="text-right">p50</th>
                        <th scope="col" class="text-right">p95</th>
                        <th scope="col" class="text-right">p99</th>
                        <th scope="col" class="text-right">max</th>
                        <th scope="col" class="text-right" title="Average queries per request (worst single request)">Queries</th>
                        <th scope="col" class="text-right" title="Average time spent in the database per request">DB</th>
                        <th scope="col" class="text-right" title="Average peak memory per request (worst single request)">Mem</th>
                        <th scope="col" class="text-right" title="Average Eloquent models hydrated per request">Models</th>
                        <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($routes as $r)
                        <tr wire:key="route-{{ $loop->index }}">
                            <td class="max-w-md truncate">
                                <x-vigilance::ui.badge tone="neutral">{{ $r->method }}</x-vigilance::ui.badge>
                                <span class="ml-1 font-mono v-strong">{{ $r->path }}</span>
                            </td>
                            <td class="text-right v-num v-muted">{{ number_format($r->count) }}</td>
                            <td class="text-right v-num">
                                <x-vigilance::ui.badge tone="{{ ($r->error_rate == 0) ? 'neutral' : (($r->error_rate > 0 && $r->error_rate <= 5) ? 'warning' : (($r->error_rate > 5) ? 'danger' : ('neutral'))) }}">{{ $r->error_rate }}%</x-vigilance::ui.badge>
                            </td>
                            <td class="text-right v-num">
                                @if ($r->apdex !== null)
                                    <x-vigilance::ui.badge tone="{{ ($r->apdex >= 0.94) ? 'success' : (($r->apdex >= 0.8 && $r->apdex < 0.94) ? 'warning' : (($r->apdex < 0.8) ? 'danger' : ('neutral'))) }}">{{ number_format($r->apdex, 2) }}</x-vigilance::ui.badge>
                                @else
                                    <span class="v-faint">—</span>
                                @endif
                            </td>
                            <td class="text-right v-num v-muted">{{ $r->p50 !== null ? $r->p50.'ms' : '—' }}</td>
                            <td class="text-right v-num font-medium v-strong">{{ $r->p95 !== null ? $r->p95.'ms' : '—' }}</td>
                            <td class="text-right v-num v-muted">{{ $r->p99 !== null ? $r->p99.'ms' : '—' }}</td>
                            <td class="text-right v-num v-faint">{{ $r->max }}ms</td>
                            <td class="text-right v-num">
                                @if ($r->queries_avg !== null)
                                    <x-vigilance::ui.badge tone="{{ ($r->queries_avg <= 20) ? 'neutral' : (($r->queries_avg > 20 && $r->queries_avg <= 50) ? 'warning' : (($r->queries_avg > 50) ? 'danger' : ('neutral'))) }}">{{ $r->queries_avg }}</x-vigilance::ui.badge>
                                    <span class="v-faint">/ {{ $r->queries_max }}</span>
                                @else
                                    <span class="v-faint">—</span>
                                @endif
                            </td>
                            <td class="text-right v-num v-muted">{{ $r->db_ms_avg !== null ? $r->db_ms_avg.'ms' : '—' }}</td>
                            <td class="text-right v-num">
                                @if ($r->memory_kb_avg !== null)
                                    <x-vigilance::ui.badge tone="{{ ($r->memory_kb_avg <= 32768) ? 'neutral' : (($r->memory_kb_avg > 32768 && $r->memory_kb_avg <= 65536) ? 'warning' : (($r->memory_kb_avg > 65536) ? 'danger' : ('neutral'))) }}">{{ number_format($r->memory_kb_avg / 1024, 1) }} MB</x-vigilance::ui.badge>
                                    <span class="v-faint">/ {{ number_format($r->memory_kb_max / 1024, 1) }}</span>
                                @else
                                    <span class="v-faint">—</span>
                                @endif
                            </td>
                            <td class="text-right v-num v-muted">{{ $r->models_avg !== null ? $r->models_avg : '—' }}</td>
                            <td class="text-right">
                                {{-- The loop this closes: Vigilance was already
                                     good at showing you the noisy route, then
                                     left you to edit config and redeploy. --}}
                                <x-vigilance::ui.button
                                    size="xs"
                                    variant="ghost"
                                    wire:click="suppress('route', '{{ $r->path }}')"
                                    wire:confirm="Stop recording telemetry for {{ $r->path }}?"
                                    title="Stop recording this route"
                                >Ignore</x-vigilance::ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="13">
                            <x-vigilance::ui.empty>
    <x-vigilance::ui.empty-title>No request data yet.</x-vigilance::ui.empty-title>
    <x-vigilance::ui.empty-description><p>Routes appear here as the Requests recorder captures traffic.</p></x-vigilance::ui.empty-description>
</x-vigilance::ui.empty>
                        </td></tr>
                    @endforelse
                </tbody>
            </x-vigilance::ui.table>

        @if ($suppressions->isNotEmpty())
            <div class="v-card--pad space-y-2 text-sm">
                <p class="v-muted">Routes you have muted — visible on purpose, because a filter you
                    cannot see is a filter you forget you applied.</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($suppressions as $rule)
                        <x-vigilance::ui.badge tone="neutral" class="gap-2">
                            <span class="font-mono">{{ $rule->pattern }}</span>
                            @if ($rule->expires_at)
                                <span class="v-faint">expires {{ $rule->expires_at->diffForHumans() }}</span>
                            @endif
                            <button type="button" wire:click="unsuppress({{ $rule->id }})"
                                    class="v-link" aria-label="Remove rule">&times;</button>
                        </x-vigilance::ui.badge>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($routes->isNotEmpty() && $routes->every(fn ($r) => $r->queries_avg === null))
            <div class="v-card--pad v-muted text-sm">
                The cost columns are empty because the <span class="font-mono">RequestProfile</span>
                recorder is off. Enable it with <span class="font-mono">VIGILANCE_APM_REQUEST_PROFILE=true</span>
                to see queries, database time, memory and hydrated models per route.
            </div>
        @endif
    </x-vigilance::ui.card>
</div>
