<?php

namespace Vigilance\Logs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Throwable;
use Vigilance\Logs\Contracts\LogStorage;
use Vigilance\Support\IncidentMode;
use Vigilance\Support\Redactor;
use Vigilance\Tracing\Tracer;

/**
 * Captures application log records into the searchable log explorer, correlating
 * each line to the in-flight trace (when tracing is sampling) so you can pivot
 * from a slow/failed trace straight to the logs it emitted.
 *
 * Records are buffered in a cheap in-memory list during the request/job and
 * persisted in one batched insert on flush — wired to the terminate phase, so
 * log capture never adds request latency. Every path is guarded: capturing a
 * log can never break the host application.
 */
class LogCollector
{
    /** @var list<array<string, mixed>> */
    protected array $buffer = [];

    protected bool $enabled;

    protected int $minLevel;

    protected float $sampleRate;

    protected int $maxMessageLength;

    protected int $maxContextLength;

    protected int $maxBuffer;

    /** @var list<string> */
    protected array $ignore;

    protected ?string $channel;

    /** Re-entrancy guard so a log emitted during flush can't recurse. */
    protected bool $flushing = false;

    public function __construct(protected Container $app)
    {
        $config = $app->make('config');

        $this->enabled = (bool) $config->get('vigilance.logs.enabled', false);
        $this->minLevel = LogLevel::value((string) $config->get('vigilance.logs.level', 'debug'));
        $this->sampleRate = (float) $config->get('vigilance.logs.sample_rate', 1.0);
        $this->maxMessageLength = (int) $config->get('vigilance.logs.max_message_length', 8000);
        $this->maxContextLength = (int) $config->get('vigilance.logs.max_context_length', 8000);
        $this->maxBuffer = (int) $config->get('vigilance.logs.max_buffer', 500);
        $this->ignore = array_values((array) $config->get('vigilance.logs.ignore', []));
        $this->channel = ($default = $config->get('logging.default')) !== null ? (string) $default : null;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Listen for log records. The buffer is flushed by the service provider on
     * the terminate / worker-loop hooks.
     */
    public function register(): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->app->make('events')->listen(
            MessageLogged::class,
            fn (MessageLogged $event) => $this->record($event),
        );
    }

    public function record(MessageLogged $event): void
    {
        if (! $this->enabled || $this->flushing) {
            return;
        }

        try {
            $value = LogLevel::value($event->level);

            if ($value < $this->minLevel() || ! $this->shouldSample() || $this->isIgnored($event->message)) {
                return;
            }

            $this->buffer[] = [
                'level' => strtolower($event->level),
                'level_value' => $value,
                'message' => Str::limit($event->message, $this->maxMessageLength),
                'context' => $this->encodeContext($event->context),
                'channel' => $this->channel,
                'trace_id' => $this->currentTraceId(),
                'logged_at' => CarbonImmutable::now()->getTimestamp(),
                'created_at' => CarbonImmutable::now(),
            ];

            if (count($this->buffer) >= $this->maxBuffer) {
                $this->flush();
            }
        } catch (Throwable) {
            // Capturing a log must never break the application.
        }
    }

    /**
     * Persist and clear the buffer. Safe to call when empty.
     */
    public function flush(): void
    {
        if ($this->buffer === [] || $this->flushing) {
            return;
        }

        $this->flushing = true;
        $rows = $this->buffer;
        $this->buffer = [];

        try {
            $this->app->make(LogStorage::class)->store($rows);
        } catch (Throwable) {
            // Swallow — a failed log write must not surface to the app.
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Drop any buffered records without persisting (used on Octane request reset
     * so logs never leak across requests).
     */
    public function discard(): void
    {
        $this->buffer = [];
    }

    public function setContainer(Container $container): void
    {
        $this->app = $container;
    }

    protected function currentTraceId(): ?string
    {
        try {
            return $this->app->make(Tracer::class)->currentTraceId();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The severity floor, lowered while incident mode is engaged — the lines
     * you filtered out for volume are usually the ones that explain the
     * incident. Resolved per call, not cached, because the switch is engaged
     * at runtime and this object outlives a request under Octane.
     */
    protected function minLevel(): int
    {
        if (IncidentMode::active() && ($level = IncidentMode::logLevel()) !== null) {
            return min($this->minLevel, LogLevel::value($level));
        }

        return $this->minLevel;
    }

    protected function shouldSample(): bool
    {
        return match (true) {
            $this->sampleRate >= 1.0 => true,
            $this->sampleRate <= 0.0 => false,
            default => (mt_rand() / mt_getrandmax()) <= $this->sampleRate,
        };
    }

    protected function isIgnored(string $message): bool
    {
        foreach ($this->ignore as $pattern) {
            if (@preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function encodeContext(array $context): ?string
    {
        if ($context === []) {
            return null;
        }

        $json = json_encode(
            Redactor::redact($context),
            JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        if ($json === false) {
            return null;
        }

        return Str::limit($json, $this->maxContextLength);
    }
}
