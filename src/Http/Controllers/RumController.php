<?php

namespace Vigilance\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vigilance\Apm\Apm;
use Vigilance\Capture\FailureGrouper;
use Vigilance\Sourcemaps\SourceMapStore;
use Vigilance\Support\PathMatcher;
use Vigilance\Vigilance;

/**
 * Public (unauthenticated, throttled) ingest endpoint for Real User Monitoring
 * beacons: Core Web Vitals / timings and uncaught browser errors. Opt-in
 * (vigilance.rum.enabled). Web Vitals are stored as APM entries keyed by
 * [metric, page] so the Routes/RUM views can compute p75; browser errors flow
 * into the unified Issues inbox as source "browser". Strictly validated and
 * capped so an open endpoint can't be used to poison or flood storage.
 */
class RumController
{
    /** Allowed Core Web Vitals / navigation timing metrics. */
    protected const METRICS = ['lcp', 'inp', 'cls', 'fcp', 'ttfb'];

    public function store(Request $request, Apm $apm, FailureGrouper $grouper): Response
    {
        abort_unless((bool) config('vigilance.rum.enabled', false), 404);

        $page = $this->normalizePage((string) $request->input('page', '/'));

        // Pages on the global ignore list (admin panels, etc.) are dropped here.
        if (PathMatcher::ignored($page)) {
            return response()->noContent();
        }

        $metrics = $request->input('metrics', []);

        if (is_array($metrics)) {
            foreach (array_slice($metrics, 0, 12) as $metric) {
                $name = is_array($metric) ? strtolower((string) ($metric['name'] ?? '')) : '';
                $value = is_array($metric) ? ($metric['value'] ?? null) : null;

                if (! in_array($name, self::METRICS, true) || ! is_numeric($value)) {
                    continue;
                }

                $value = (int) round((float) $value);

                if ($value < 0 || $value > 3_600_000) {
                    continue;
                }

                $apm->record('web_vital', (string) json_encode([$name, $page]), $value)->count()->avg()->max();
            }
        }

        $errors = $request->input('errors', []);

        if (is_array($errors) && (bool) config('vigilance.rum.capture_js_errors', true)) {
            $release = is_string($request->input('release')) && $request->input('release') !== ''
                ? (string) $request->input('release')
                : Vigilance::currentRelease();

            foreach (array_slice($errors, 0, 5) as $error) {
                $message = is_array($error) ? (string) ($error['message'] ?? '') : (string) $error;

                if ($message === '') {
                    continue;
                }

                $stack = mb_substr(is_array($error) ? (string) ($error['stack'] ?? $message) : $message, 0, 8000);

                // Symbolicate the minified stack against uploaded source maps so
                // the issue sample shows the original source locations.
                if ((bool) config('vigilance.rum.symbolicate', true)) {
                    $symbolicated = app(SourceMapStore::class)->safeSymbolicate($release, $stack);
                    if ($symbolicated !== null) {
                        $stack = mb_substr($symbolicated, 0, 8000);
                    }
                }

                $grouper->record(
                    type: 'browser',
                    name: $page,
                    exceptionClass: 'JavaScriptError',
                    message: mb_substr($message, 0, 500),
                    source: 'browser',
                    sample: $stack,
                    context: array_filter([
                        'page' => $page,
                        'release' => $release,
                        'source' => is_array($error) ? mb_substr((string) ($error['source'] ?? ''), 0, 300) : null,
                        'agent' => mb_substr((string) $request->userAgent(), 0, 200),
                    ]),
                );
            }
        }

        return response()->noContent();
    }

    /** Strip the query/host, keep a bounded path so page cardinality stays sane. */
    protected function normalizePage(string $page): string
    {
        $path = parse_url($page, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        return '/'.ltrim(mb_substr($path, 0, 200), '/');
    }
}
