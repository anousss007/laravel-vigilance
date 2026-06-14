<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Database\Events\QueryExecuted;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\LocatesCode;
use Vigilance\Apm\Recorders\Concerns\Sampling;
use Vigilance\Apm\Recorders\Concerns\Thresholds;

/**
 * Records queries slower than the threshold, keyed by SQL + the application
 * location that issued them.
 */
class SlowQueries extends Recorder
{
    use Ignores;
    use LocatesCode;
    use Sampling;
    use Thresholds;

    /** @var list<class-string> */
    public array $listen = [QueryExecuted::class];

    public function record(QueryExecuted $event): void
    {
        $duration = (int) round($event->time);

        // Cheap synchronous gate: fast queries (the vast majority) cost only this.
        if ($this->underThreshold($duration, $event->sql)) {
            return;
        }

        $now = time();
        $sql = $event->sql;
        $trace = $this->captureTrace();

        $this->apm->lazy(function () use ($now, $duration, $sql, $trace) {
            if ($this->shouldIgnore($sql) || ! $this->shouldSample()) {
                return;
            }

            $key = json_encode([
                'sql' => $this->truncate($sql),
                'location' => $this->locationFromTrace($trace),
            ]);

            $this->apm->record('slow_query', (string) $key, $duration, $now)->max()->count();
        });
    }

    protected function truncate(string $sql): string
    {
        $max = (int) $this->recorderConfig('max_query_length', 0);

        return $max > 0 ? mb_substr($sql, 0, $max) : $sql;
    }
}
