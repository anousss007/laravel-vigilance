<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;
use Vigilance\Apm\Apm;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\LocatesCode;
use Vigilance\Apm\Recorders\Concerns\Sampling;
use Vigilance\Events\ExceptionReported;
use Vigilance\Tracing\Tracer;

/**
 * Records reported exceptions (any layer — not just jobs), keyed by class +
 * application location. The value is the timestamp so max() = latest occurrence.
 *
 * Listens to both the framework's reportable() hook and Vigilance::report()
 * (the ExceptionReported event) so caught/swallowed exceptions can be surfaced.
 * When a trace is active the exception is also dropped onto its waterfall as a
 * span, linking the exception to the request/job it happened in.
 */
class Exceptions extends Recorder
{
    use Ignores;
    use LocatesCode;
    use Sampling;

    /** @var list<class-string> */
    public array $listen = [ExceptionReported::class];

    public function register(Apm $apm): void
    {
        app(ExceptionHandler::class)->reportable(function (Throwable $e) {
            $this->record($e);
        });
    }

    public function record(Throwable|ExceptionReported $e): void
    {
        $e = $e instanceof ExceptionReported ? $e->exception : $e;

        $now = time();
        $class = $e::class;
        $trace = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());

        $this->linkToTrace($class);

        $this->apm->lazy(function () use ($class, $trace, $now) {
            if ($this->shouldIgnore($class) || ! $this->shouldSample()) {
                return;
            }

            $key = json_encode([
                'class' => $class,
                'location' => $this->locationFromTrace($trace),
            ]);

            $this->apm->record('exception', (string) $key, $now, $now)->max()->count();
        });
    }

    /**
     * Drop an "exception" span onto the active trace (if any), so the waterfall
     * shows where in the request/job the exception was thrown.
     */
    protected function linkToTrace(string $class): void
    {
        $tracer = $this->apm->container()->make(Tracer::class);

        if (! $tracer->sampling()) {
            return;
        }

        $at = microtime(true);

        $tracer->rescue(fn () => $tracer->span('exception', $class, $at, $at, ['class' => $class]));
    }
}
