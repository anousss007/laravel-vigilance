<?php

namespace Vigilance\Metrics;

/**
 * One custom metric over a window. For counters, "value" is the total (sum) and
 * "peak" the number of events; for gauges, "value" is the average and "peak"
 * the maximum. "series" is a null-padded sparkline.
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
    ) {}
}
