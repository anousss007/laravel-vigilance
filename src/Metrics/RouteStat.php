<?php

namespace Vigilance\Metrics;

/**
 * One route's HTTP performance over a window. Latencies are milliseconds;
 * apdex is 0–1 (null when no Apdex samples exist for the route).
 *
 * The cost fields — queries, database time, peak memory and hydrated models —
 * come from the RequestProfile recorder and are null when it is disabled.
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
        public ?float $queries_avg = null,
        public ?int $queries_max = null,
        public ?int $db_ms_avg = null,
        public ?int $memory_kb_avg = null,
        public ?int $memory_kb_max = null,
        public ?float $models_avg = null,
    ) {}
}
