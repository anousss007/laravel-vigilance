<?php

namespace Vigilance\Notifications\Rules;

use Illuminate\Support\Carbon;
use Vigilance\Enums\RunStatus;
use Vigilance\Models\Run;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires when the job/command failure rate over the last hour exceeds the
 * configured percentage (and a minimum sample size, to avoid noise on low
 * volume).
 */
class ErrorRateRule implements AlertRule
{
    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.error_rate.enabled', true)) {
            return;
        }

        $minRuns = (int) config('vigilance.alerts.rules.error_rate.min_runs', 20);
        $percentThreshold = (float) config('vigilance.alerts.rules.error_rate.percent', 20);

        $since = Carbon::now()->subHour();

        $total = Run::query()->where('created_at', '>=', $since)->count();

        if ($total < $minRuns) {
            return;
        }

        $failed = Run::query()->where('created_at', '>=', $since)->where('status', RunStatus::Failed->value)->count();
        $percent = round($failed / max(1, $total) * 100, 1);

        if ($percent < $percentThreshold) {
            return;
        }

        // Name the jobs/commands failing the most (with their exception) so the
        // alert points at the culprit, not just a percentage.
        $top = Run::query()
            ->toBase()
            ->where('created_at', '>=', $since)
            ->where('status', RunStatus::Failed->value)
            ->selectRaw('name, exception_class, count(*) as c')
            ->groupBy('name', 'exception_class')
            ->orderByDesc('c')
            ->limit(3)
            ->get()
            ->map(fn ($r) => ((string) ($r->name ?? '') ?: 'unknown')
                .($r->exception_class ? ' ('.class_basename((string) $r->exception_class).')' : '')
                .' ×'.(int) $r->c)
            ->all();

        $detail = $top !== [] ? ' Top: '.implode('; ', $top).'.' : '';

        // The throttle key includes the rounded percent bucket so a worsening
        // incident can re-alert without spamming on every tick.
        yield new Alert(
            key: 'error_rate',
            title: 'Elevated failure rate',
            message: "Failure rate is {$percent}% over the last hour ({$failed}/{$total} runs failed).{$detail}",
            level: 'critical',
        );
    }
}
