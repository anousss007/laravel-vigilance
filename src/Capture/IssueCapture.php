<?php

namespace Vigilance\Capture;

use Illuminate\Support\Str;
use Throwable;
use Vigilance\Support\Redactor;
use Vigilance\Vigilance;

/**
 * Routes any reported exception — HTTP request errors and Vigilance::report()
 * surfaced exceptions — into the unified issue store (FailureGroup), enriched
 * with a bounded stack-trace sample and request context. Queue/command failures
 * are already grouped by the run-capture path, so this deliberately covers the
 * layers that path does not. Capture is guarded and never breaks the app.
 */
class IssueCapture
{
    public function __construct(protected FailureGrouper $grouper) {}

    public function capture(Throwable $e, string $source): void
    {
        if (! config('vigilance.issues.enabled', true)) {
            return;
        }

        try {
            $class = $e::class;

            if ($this->shouldIgnore($class) || ! $this->shouldSample()) {
                return;
            }

            Vigilance::withoutRecording(fn () => $this->grouper->record(
                type: $source,
                name: $this->name(),
                exceptionClass: $class,
                message: $e->getMessage() !== '' ? $e->getMessage() : null,
                source: $source,
                sample: $this->sample($e),
                context: $this->context($e),
            ));
        } catch (Throwable) {
            // Capturing an issue must never break the application.
        }
    }

    protected function shouldIgnore(string $class): bool
    {
        foreach ((array) config('vigilance.issues.except', []) as $pattern) {
            if ($class === $pattern || Str::is($pattern, $class)) {
                return true;
            }
        }

        return false;
    }

    protected function shouldSample(): bool
    {
        $rate = (float) config('vigilance.issues.sample_rate', 1.0);

        return match (true) {
            $rate >= 1.0 => true,
            $rate <= 0.0 => false,
            default => (mt_rand() / mt_getrandmax()) <= $rate,
        };
    }

    protected function name(): ?string
    {
        if (app()->runningInConsole()) {
            return null;
        }

        $request = request();
        $route = $request->route();

        return ($route?->getName()) ?: (trim($request->method().' '.$request->path()) ?: null);
    }

    protected function sample(Throwable $e): string
    {
        $max = (int) config('vigilance.issues.max_sample', 8000);

        return Str::limit($e::class.': '.$e->getMessage()."\n".$e->getTraceAsString(), $max);
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(Throwable $e): array
    {
        $context = [
            'file' => $e->getFile().':'.$e->getLine(),
            'release' => (string) (config('vigilance.release') ?? config('app.version') ?? '') ?: null,
        ];

        if (! app()->runningInConsole()) {
            $request = request();

            $context['method'] = $request->method();
            $context['url'] = $request->fullUrl();
            $context['route'] = $request->route()?->getName();
            $context['user'] = Vigilance::currentUser($request);

            if (config('vigilance.issues.capture_request_input', false)) {
                $context['input'] = Redactor::redact($request->except(['password', 'password_confirmation']));
            }
        }

        return array_filter($context, static fn ($v): bool => $v !== null && $v !== '');
    }
}
