<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Log\Events\MessageLogged;
use Vigilance\Apm\Recorders\Concerns\Sampling;

/**
 * Records log entries by level, powering the "logs by level" card. By default
 * only warning-and-above are kept so debug/info chatter doesn't become APM
 * write volume — widen via the "levels" config.
 */
class Logs extends Recorder
{
    use Sampling;

    /** @var list<class-string> */
    public array $listen = [MessageLogged::class];

    public function record(MessageLogged $event): void
    {
        $level = (string) $event->level;

        // Cheap synchronous gate before any deferred work.
        if (! in_array($level, $this->levels(), true)) {
            return;
        }

        $now = time();

        $this->apm->lazy(function () use ($now, $level) {
            if (! $this->shouldSample()) {
                return;
            }

            $this->apm->record('log', $level, null, $now)->count();
        });
    }

    /** @return list<string> */
    protected function levels(): array
    {
        $levels = $this->recorderConfig('levels', ['warning', 'error', 'critical', 'alert', 'emergency']);

        return array_values(array_map('strval', (array) $levels));
    }
}
