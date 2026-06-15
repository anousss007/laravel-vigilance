<?php

namespace Vigilance\Notifications\Rules;

use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Vigilance\Apm\Contracts\Storage;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Dynamic-baseline anomaly detection. For each watched metric it pulls the
 * recent per-key time series from the APM aggregates, computes the mean and
 * standard deviation of the trailing baseline, and fires when the latest bucket
 * is several standard deviations above it (a z-score). This catches the
 * "something is wrong but no static threshold would have caught it" class of
 * problem — a route's latency suddenly 5σ over its own normal — without the
 * alert fatigue of fixed thresholds.
 *
 * Guarded against false positives: a baseline must have enough points, the
 * series must actually vary, and the current value must clear both an absolute
 * floor and a real multiple of the mean before anything fires.
 */
class AnomalyRule implements AlertRule
{
    public function __construct(protected Storage $storage) {}

    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.anomaly.enabled', true) || ! config('vigilance.apm.enabled', true)) {
            return;
        }

        $z = (float) config('vigilance.alerts.rules.anomaly.z', 3.0);
        $minBaseline = (int) config('vigilance.alerts.rules.anomaly.min_baseline', 12);
        $limit = (int) config('vigilance.alerts.rules.anomaly.limit', 8);
        $interval = $this->interval();
        $fired = 0;

        foreach ($this->metrics() as $metric) {
            $type = (string) $metric['type'];
            $aggregate = (string) $metric['aggregate'];
            $label = (string) ($metric['label'] ?? $type);
            $unit = (string) ($metric['unit'] ?? '');
            $minValue = (float) ($metric['min_value'] ?? 0);

            $series = $this->storage->graph([$type], $aggregate, $interval);

            foreach ($series as $key => $byType) {
                if ($fired >= $limit) {
                    return;
                }

                // The graph contract guarantees a nested Collection per key.
                $raw = $byType->get($type);

                $points = collect(is_iterable($raw) ? $raw : [])
                    ->filter(fn ($v) => $v !== null)
                    ->map(fn ($v) => (float) $v)
                    ->values();

                if ($points->count() < $minBaseline + 1) {
                    continue;
                }

                $current = (float) $points->last();
                $baseline = $points->slice(0, $points->count() - 1);
                $mean = (float) $baseline->avg();
                $std = $this->stddev($baseline, $mean);

                if ($std <= 0.0 || $mean <= 0.0) {
                    continue;
                }

                $score = ($current - $mean) / $std;

                // Must be a genuine, sizeable jump — not statistical noise on a
                // metric that barely moved in absolute terms.
                if ($score < $z || $current < $minValue || $current < $mean * 1.5) {
                    continue;
                }

                $fired++;
                $name = $this->prettyKey((string) $key);

                yield new Alert(
                    key: "anomaly:{$type}:{$key}",
                    title: "Anomaly: {$label} on {$name}",
                    message: sprintf(
                        '%s for "%s" is %s — %.1fσ above its %s baseline of %s.',
                        ucfirst($label),
                        $name,
                        $this->fmt($current, $unit),
                        $score,
                        $this->windowLabel(),
                        $this->fmt($mean, $unit),
                    ),
                    level: $score >= $z + 2 ? 'critical' : 'warning',
                );
            }
        }
    }

    /**
     * @param  Collection<int, float>  $values
     */
    protected function stddev(Collection $values, float $mean): float
    {
        if ($values->count() < 2) {
            return 0.0;
        }

        $variance = (float) $values->map(fn (float $v) => ($v - $mean) ** 2)->avg();

        return sqrt($variance);
    }

    /**
     * The watched metrics. Defaults cover request latency, 5xx error rate and
     * reported exceptions; override under alerts.rules.anomaly.metrics.
     *
     * @return list<array{type: string, aggregate: string, label?: string, unit?: string, min_value?: int|float}>
     */
    protected function metrics(): array
    {
        $configured = config('vigilance.alerts.rules.anomaly.metrics');

        if (is_array($configured) && $configured !== []) {
            return array_values($configured);
        }

        return [
            ['type' => 'request', 'aggregate' => 'avg', 'label' => 'latency', 'unit' => 'ms', 'min_value' => 150],
            ['type' => 'request_error', 'aggregate' => 'count', 'label' => 'error rate', 'min_value' => 5],
            ['type' => 'exception', 'aggregate' => 'count', 'label' => 'exceptions', 'min_value' => 5],
        ];
    }

    protected function interval(): CarbonInterval
    {
        return match ((string) config('vigilance.alerts.rules.anomaly.window', '6h')) {
            '1h' => CarbonInterval::hour(),
            '24h' => CarbonInterval::hours(24),
            default => CarbonInterval::hours(6),
        };
    }

    protected function windowLabel(): string
    {
        return match ((string) config('vigilance.alerts.rules.anomaly.window', '6h')) {
            '1h' => '1h',
            '24h' => '24h',
            default => '6h',
        };
    }

    /**
     * Request/job keys are JSON arrays like ["GET","/checkout"]; show them as
     * "GET /checkout" rather than the raw JSON.
     */
    protected function prettyKey(string $key): string
    {
        $decoded = json_decode($key, true);

        if (is_array($decoded)) {
            return implode(' ', array_map(fn ($v) => (string) $v, $decoded));
        }

        return $key;
    }

    protected function fmt(float $value, string $unit): string
    {
        if ($unit === 'ms') {
            return $value < 1000 ? round($value).'ms' : round($value / 1000, 2).'s';
        }

        return (string) round($value, $value < 10 ? 1 : 0);
    }
}
