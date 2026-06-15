<?php

namespace Vigilance\Notifications\Rules;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Vigilance\Models\FailureGroup;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires when a previously-resolved issue starts happening again — a regression.
 * The grouper stamps `regressed_at` when a resolved group recurs; this rule
 * surfaces those at snapshot time (throttled per issue), the way Sentry alerts
 * on a resolved issue reappearing. A regression is treated as critical.
 */
class IssueRegressionRule implements AlertRule
{
    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.issue_regression.enabled', true) || ! config('vigilance.issues.enabled', true)) {
            return;
        }

        $window = (int) (config('vigilance.alerts.rules.issue_regression.window_minutes')
            ?? config('vigilance.alerts.throttle_minutes', 15));
        $limit = (int) config('vigilance.alerts.rules.issue_regression.limit', 10);
        $cutoff = Carbon::now()->subMinutes(max(1, $window));

        $groups = FailureGroup::query()
            ->whereNull('resolved_at')
            ->whereNotNull('regressed_at')
            ->where('regressed_at', '>=', $cutoff)
            ->where(fn ($q) => $q->whereNull('muted_until')->orWhere('muted_until', '<=', Carbon::now()))
            ->orderByDesc('regressed_at')
            ->limit(max(1, $limit))
            ->get();

        foreach ($groups as $group) {
            $label = $group->name ?: $group->exception_class ?: 'error';
            $prefix = $group->exception_class ? $group->exception_class.': ' : '';

            yield new Alert(
                key: 'issue_regressed:'.$group->id,
                title: 'Regression: '.$label,
                message: 'A resolved issue is happening again — '.$prefix.Str::limit((string) $group->message, 140),
                level: 'critical',
            );
        }
    }
}
