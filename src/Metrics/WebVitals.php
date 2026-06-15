<?php

namespace Vigilance\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-page Core Web Vitals at p75, computed from the raw RUM beacon values the
 * ingest endpoint stores as `web_vital` entries keyed by [metric, page].
 */
class WebVitals
{
    /**
     * @return Collection<int, WebVitalStat>
     */
    public function forInterval(CarbonInterval $interval, int $limit = 50): Collection
    {
        $windowStart = CarbonImmutable::now()->getTimestamp() - (int) $interval->totalSeconds + 1;

        $rows = DB::connection(config('vigilance.storage.connection') ?: config('database.default'))
            ->table('vigilance_entries')
            ->select('key', 'value')
            ->where('type', 'web_vital')
            ->where('timestamp', '>=', $windowStart)
            ->whereNotNull('value')
            ->get();

        /** @var array<string, array<string, list<int>>> $pages */
        $pages = [];
        /** @var array<string, int> $samples */
        $samples = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->key, true);

            if (! is_array($decoded) || count($decoded) < 2) {
                continue;
            }

            $metric = (string) $decoded[0];
            $page = (string) $decoded[1];

            $pages[$page][$metric][] = (int) $row->value;
            $samples[$page] = ($samples[$page] ?? 0) + 1;
        }

        $stats = [];

        foreach ($pages as $page => $metrics) {
            $p75 = fn (string $m): ?int => isset($metrics[$m]) ? $this->quantile($metrics[$m], 0.75) : null;

            $stats[] = new WebVitalStat(
                page: $page,
                samples: $samples[$page] ?? 0,
                lcp: $p75('lcp'),
                inp: $p75('inp'),
                cls: $p75('cls'),
                fcp: $p75('fcp'),
                ttfb: $p75('ttfb'),
            );
        }

        usort($stats, fn (WebVitalStat $a, WebVitalStat $b): int => $b->samples <=> $a->samples);

        return new Collection(array_slice($stats, 0, $limit));
    }

    /**
     * Nearest-rank percentile of a list of values.
     *
     * @param  list<int>  $values
     */
    protected function quantile(array $values, float $q): int
    {
        sort($values);
        $n = count($values);

        if ($n === 0) {
            return 0;
        }

        $rank = max(0, min($n - 1, (int) ceil($q * $n) - 1));

        return $values[$rank];
    }
}
