<?php

namespace Vigilance\Notifications\Rules;

use Vigilance\Metrics\Slo;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires for each configured SLO that is breaching its target or burning its
 * error budget faster than the configured multiple (Google SRE-style fast-burn
 * alerting). No configured SLOs means nothing to evaluate.
 */
class SloBurnRateRule implements AlertRule
{
    public function __construct(protected Slo $slo) {}

    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.slo_burn.enabled', true)) {
            return;
        }

        $threshold = (float) config('vigilance.alerts.rules.slo_burn.burn_rate', 2.0);

        foreach ($this->slo->all() as $slo) {
            if ($slo->events === 0) {
                continue;
            }

            $breaching = $slo->current < $slo->target;

            if (! $breaching && $slo->burnRate < $threshold) {
                continue;
            }

            yield new Alert(
                key: 'slo_burn:'.$slo->id,
                title: $breaching ? "SLO breaching: {$slo->name}" : "SLO burning fast: {$slo->name}",
                message: $breaching
                    ? "{$slo->name} is at {$slo->current}% over {$slo->windowDays}d (target {$slo->target}%); error budget exhausted."
                    : "{$slo->name} is burning its error budget at {$slo->burnRate}× the sustainable rate (target {$slo->target}%).",
                level: $breaching ? 'critical' : 'warning',
            );
        }
    }
}
