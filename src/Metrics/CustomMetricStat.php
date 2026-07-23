<?php

namespace Vigilance\Metrics;

/**
 * One custom metric over a window. For counters, "value" is the total (sum) and
 * "peak" the number of events; for gauges, "value" is the average and "peak"
 * the maximum; for distributions, "value" is the average, "peak" the max, and
 * p50/p95/p99 the percentiles over the retained samples. "series" is a
 * null-padded sparkline.
 */
class CustomMetricStat
{
    /**
     * @param  list<int|null>  $series
     */
    public function __construct(
        public string $name,
        public string $type,
        public int $value,
        public int $peak,
        public array $series,
        public ?int $p50 = null,
        public ?int $p95 = null,
        public ?int $p99 = null,
    ) {}
}
