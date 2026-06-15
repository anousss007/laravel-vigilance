<?php

namespace Vigilance\Metrics;

/**
 * One page's Core Web Vitals at p75 over a window. Timings are milliseconds;
 * CLS is stored ×1000 (so 0.12 → 120). Ratings use Google's CWV thresholds.
 */
class WebVitalStat
{
    public function __construct(
        public string $page,
        public int $samples,
        public ?int $lcp,
        public ?int $inp,
        public ?int $cls,
        public ?int $fcp,
        public ?int $ttfb,
    ) {}

    /**
     * good | needs-improvement | poor | unknown for a single metric (p75).
     */
    public function rating(string $metric): string
    {
        [$value, $good, $needs] = match ($metric) {
            'lcp' => [$this->lcp, 2500, 4000],
            'inp' => [$this->inp, 200, 500],
            'cls' => [$this->cls, 100, 250],
            'fcp' => [$this->fcp, 1800, 3000],
            'ttfb' => [$this->ttfb, 800, 1800],
            default => [null, 0, 0],
        };

        if ($value === null) {
            return 'unknown';
        }

        return match (true) {
            $value <= $good => 'good',
            $value <= $needs => 'needs-improvement',
            default => 'poor',
        };
    }

    /** The worst rating across the three Core Web Vitals (LCP, INP, CLS). */
    public function overall(): string
    {
        $rank = ['unknown' => -1, 'good' => 0, 'needs-improvement' => 1, 'poor' => 2];
        $worst = 'unknown';

        foreach (['lcp', 'inp', 'cls'] as $metric) {
            $rating = $this->rating($metric);

            if ($rating !== 'unknown' && $rank[$rating] > $rank[$worst]) {
                $worst = $rating;
            }
        }

        return $worst;
    }
}
