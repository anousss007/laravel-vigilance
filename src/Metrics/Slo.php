<?php

namespace Vigilance\Metrics;

use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Vigilance\Apm\Contracts\Storage;

/**
 * Computes the configured service-level objectives from the HTTP request
 * telemetry (Feature 2). Two indicators are supported:
 *   - success_rate: 1 − (5xx / requests)
 *   - latency:      the Apdex score (share of fast-enough requests)
 *
 * Windows are clamped to the APM retention (≤ 7 days). The short-window burn
 * rate uses the last hour. SLOs are global (across all routes) for now.
 */
class Slo
{
    public function __construct(protected Storage $storage) {}

    /**
     * @return Collection<int, SloStatus>
     */
    public function all(): Collection
    {
        $out = new Collection;

        foreach ((array) config('vigilance.slos', []) as $id => $def) {
            if (is_array($def)) {
                $out->push($this->compute((string) $id, $def));
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $def
     */
    protected function compute(string $id, array $def): SloStatus
    {
        $sli = in_array($def['sli'] ?? 'success_rate', ['success_rate', 'latency'], true) ? (string) $def['sli'] : 'success_rate';
        $target = (float) ($def['target'] ?? 99.9);
        $windowDays = max(1, min(7, (int) ($def['window_days'] ?? 7)));
        $name = (string) ($def['name'] ?? $id);

        $window = CarbonInterval::days($windowDays);

        $total = (int) $this->storage->aggregateTotal('request', 'count', $window);
        $current = $this->sliValue($sli, $window, $total);

        $recentTotal = (int) $this->storage->aggregateTotal('request', 'count', CarbonInterval::hour());
        $recent = $this->sliValue($sli, CarbonInterval::hour(), $recentTotal);

        $budget = max(0.0001, 100 - $target);
        $consumed = (100 - $current) / $budget;

        return new SloStatus(
            id: $id,
            name: $name,
            sli: $sli,
            target: $target,
            windowDays: $windowDays,
            current: round($current, 3),
            budgetRemaining: max(0.0, round((1 - $consumed) * 100, 1)),
            burnRate: round((100 - $recent) / $budget, 2),
            events: $total,
        );
    }

    /** The SLI as a percentage (0–100) over the given window. */
    protected function sliValue(string $type, CarbonInterval $window, int $total): float
    {
        if ($total === 0) {
            return 100.0;
        }

        if ($type === 'latency') {
            return min(100.0, (float) $this->storage->aggregateTotal('request_apdex', 'avg', $window));
        }

        $bad = (int) $this->storage->aggregateTotal('request_error', 'count', $window);

        return round(max(0, $total - $bad) / $total * 100, 4);
    }
}
