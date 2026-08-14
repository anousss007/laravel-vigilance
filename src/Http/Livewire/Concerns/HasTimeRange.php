<?php

namespace Vigilance\Http\Livewire\Concerns;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Url;

/**
 * One time-range control for every page.
 *
 * The dashboard used to carry five separate implementations of the same idea,
 * with three different option sets and two different property names, and the
 * choice was lost the moment you navigated. Here it is defined once and
 * remembered, so following a spike from Routes to APM to Traces keeps the same
 * window instead of silently snapping back to the default.
 *
 * The ranges are not arbitrary: APM buckets are only written for these periods
 * (see DatabaseStorage::periods()), and a window with no matching period reads
 * back as zero rather than as an error. Adding one here means adding one there.
 */
trait HasTimeRange
{
    #[Url(as: 'range')]
    public string $range = '';

    /** @return list<string> */
    public function ranges(): array
    {
        return ['15m', '1h', '6h', '24h', '7d'];
    }

    /** @return array<string, string> */
    public function rangeLabels(): array
    {
        return [
            '15m' => 'Last 15 min',
            '1h' => 'Last hour',
            '6h' => 'Last 6h',
            '24h' => 'Last 24h',
            '7d' => 'Last 7 days',
        ];
    }

    public function mountHasTimeRange(): void
    {
        if ($this->range === '') {
            $this->range = $this->rememberedRange();
        }
    }

    public function setRange(string $range): void
    {
        if (! in_array($range, $this->ranges(), true)) {
            return;
        }

        $this->range = $range;

        // Remembered per session, not per component: the point is that the
        // window follows you across pages.
        Session::put('vigilance.range', $range);
    }

    protected function interval(): CarbonInterval
    {
        return match ($this->range) {
            '15m' => CarbonInterval::minutes(15),
            '6h' => CarbonInterval::hours(6),
            '24h' => CarbonInterval::hours(24),
            '7d' => CarbonInterval::days(7),
            default => CarbonInterval::hour(),
        };
    }

    /**
     * The page's own default when nothing has been chosen yet — Web Vitals is
     * meaningless over 15 minutes, throughput is not.
     */
    protected function defaultRange(): string
    {
        return '1h';
    }

    protected function rememberedRange(): string
    {
        $remembered = Session::get('vigilance.range');

        return is_string($remembered) && in_array($remembered, $this->ranges(), true)
            ? $remembered
            : $this->defaultRange();
    }
}
