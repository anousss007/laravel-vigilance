<?php

namespace Vigilance\Capture;

use Illuminate\Support\Str;
use Throwable;
use Vigilance\Support\Breadcrumbs;
use Vigilance\Support\CodeLocation;
use Vigilance\Support\ExceptionChain;
use Vigilance\Support\LivewireContext;
use Vigilance\Support\PathMatcher;
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
            // Unwind the getPrevious() chain: a wrapper (a Blade/Livewire
            // ViewException, say) hides the real fault. Fingerprint, name and
            // sample by the ROOT cause so wrappers don't split or merge bugs.
            $chain = ExceptionChain::from($e);

            // Honour the ignore list against either end of the chain, so listing
            // the wrapper OR the root both work.
            if ($this->shouldIgnore($e::class) || $this->shouldIgnore($chain->rootClass())
                || ! $this->shouldSample() || $this->onIgnoredPath($source)) {
                return;
            }

            Vigilance::withoutRecording(fn () => $this->grouper->record(
                type: $source,
                name: $this->name(),
                exceptionClass: $chain->rootClass(),
                message: $chain->rootMessage(),
                source: $source,
                sample: $this->sample($chain),
                context: $this->context($chain),
            ));
        } catch (Throwable) {
            // Capturing an issue must never break the application.
        }
    }

    /**
     * Drop web-request errors raised on a globally-ignored path (admin panels,
     * etc.). Job/command/reported errors aren't path-based, so they're never
     * affected.
     */
    protected function onIgnoredPath(string $source): bool
    {
        if ($source !== 'request' || app()->runningInConsole()) {
            return false;
        }

        return PathMatcher::ignored('/'.ltrim(request()->path(), '/'));
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

        // A "livewire/update" URL names no component, so the component recovered
        // from the payload is the only meaningful culprit — prefer it over the
        // (useless) livewire.update route name.
        if ($livewire = LivewireContext::culprit()) {
            return $livewire;
        }

        $request = request();
        $route = $request->route();

        return ($route?->getName()) ?: (trim($request->method().' '.$request->path()) ?: null);
    }

    protected function sample(ExceptionChain $chain): string
    {
        return $chain->sample((int) config('vigilance.issues.max_sample', 8000));
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(ExceptionChain $chain): array
    {
        $context = [
            // The root cause's throw location, and the promoted application frame
            // (the line to actually fix — not the wrapper's handleViewException).
            'file' => CodeLocation::relative($chain->root->getFile()).':'.$chain->root->getLine(),
            'culprit' => $chain->culprit(),
            'release' => (string) (config('vigilance.release') ?? config('app.version') ?? '') ?: null,
        ];

        if (! app()->runningInConsole()) {
            $request = request();

            $context['method'] = $request->method();
            $context['url'] = $request->fullUrl();
            $context['route'] = $request->route()?->getName();
            // The Livewire component behind an opaque "livewire/update" request —
            // the single most useful tag for a Filament/Livewire render error.
            $context['livewire'] = LivewireContext::component($request);
            $context['user'] = Vigilance::currentUser($request);

            if (config('vigilance.issues.capture_request_input', false)) {
                $context['input'] = Redactor::redact($request->except(['password', 'password_confirmation']));
            }
        }

        $context = array_filter($context, static fn ($v): bool => $v !== null && $v !== '');

        // The trail of events (logs + manual breadcrumbs) leading up to the error.
        return array_merge($context, app(Breadcrumbs::class)->contextFragment());
    }
}
