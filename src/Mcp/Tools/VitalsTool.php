<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Metrics\WebVitals;
use Vigilance\Metrics\WebVitalStat;

#[Description('Real User Monitoring — Core Web Vitals at p75 per page (LCP/INP/CLS/FCP/TTFB) from real visitors, with a good / needs-improvement / poor rating. JS errors from visitors land in the Issues inbox (source "browser"). Requires RUM enabled (VIGILANCE_RUM=true).')]
#[IsReadOnly]
class VitalsTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'window' => $schema->string()
                ->description('Time window, e.g. "24h", "7d". Defaults to "24h".'),
            'limit' => $schema->integer()
                ->description('Max pages to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, WebVitals $vitals): Response
    {
        $window = (string) ($request->get('window') ?: '24h');
        $limit = $this->resolveLimit($request->integer('limit'));

        $pages = $vitals->forInterval($this->interval($window), $limit)
            ->map(fn (WebVitalStat $v): array => [
                'page' => $v->page,
                'samples' => $v->samples,
                'rating' => $v->overall(),
                'lcp_ms' => $v->lcp,
                'inp_ms' => $v->inp,
                'cls' => $v->cls !== null ? round($v->cls / 1000, 3) : null,
                'fcp_ms' => $v->fcp,
                'ttfb_ms' => $v->ttfb,
            ])->all();

        return $this->json([
            'window' => $window,
            'count' => count($pages),
            'pages' => $pages,
        ]);
    }
}
