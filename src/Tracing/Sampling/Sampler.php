<?php

namespace Vigilance\Tracing\Sampling;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Lottery;

/**
 * Decides whether a trace is head-sampled (kept for baseline even when it is
 * fast and successful). Slow and errored traces are always kept regardless of
 * this decision — that rule lives in the Tracer, not here.
 *
 * The default sample rate is 0: out of the box, tracing keeps only the traces
 * you would actually open (slow or failed), which is what keeps it cheap under
 * heavy traffic.
 */
class Sampler
{
    public function __construct(protected Repository $config) {}

    public function shouldSample(string $type): bool
    {
        $rate = $this->rate($type);

        if ($rate >= 1) {
            return true;
        }

        if ($rate <= 0) {
            return false;
        }

        return Lottery::odds($rate)->choose();
    }

    protected function rate(string $type): float
    {
        $rates = $this->config->get('vigilance.tracing.sample_rate', 0);

        if (is_array($rates)) {
            return (float) ($rates[$type] ?? $rates['default'] ?? 0);
        }

        return (float) $rates;
    }
}
