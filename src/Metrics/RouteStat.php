<?php

namespace Vigilance\Metrics;

/**
 * One route's HTTP performance over a window. Latencies are milliseconds;
 * apdex is 0–1 (null when no Apdex samples exist for the route).
 */
class RouteStat
{
    public function __construct(
        public string $method,
        public string $path,
        public int $count,
        public int $errors,
        public float $error_rate,
        public ?float $apdex,
        public int $avg,
        public int $max,
        public ?int $p50,
        public ?int $p95,
        public ?int $p99,
    ) {}
}
