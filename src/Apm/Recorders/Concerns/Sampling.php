<?php

namespace Vigilance\Apm\Recorders\Concerns;

use Illuminate\Support\Lottery;

trait Sampling
{
    /**
     * Independent random sampling for uncorrelated events.
     */
    protected function shouldSample(?float $rate = null): bool
    {
        $rate ??= (float) $this->recorderConfig('sample_rate', 1);

        if ($rate >= 1) {
            return true;
        }

        if ($rate <= 0) {
            return false;
        }

        return Lottery::odds($rate)->choose();
    }

    /**
     * Deterministic sampling keyed by a stable seed (e.g. a job UUID), so all
     * correlated events of one unit are kept-or-dropped together.
     */
    protected function shouldSampleDeterministically(string $seed, ?float $rate = null): bool
    {
        $rate ??= (float) $this->recorderConfig('sample_rate', 1);

        if ($rate >= 1) {
            return true;
        }

        if ($rate <= 0) {
            return false;
        }

        return (hexdec(substr(md5($seed), 0, 8)) / 0xFFFFFFFF) <= $rate;
    }
}
