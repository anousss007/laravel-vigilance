<?php

namespace Vigilance\Notifications\Rules;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Vigilance\Models\FailureGroup;
use Vigilance\Notifications\Alert;
use Vigilance\Notifications\Contracts\AlertRule;

/**
 * Fires once for each brand-new error type — an issue whose signature was seen
 * for the first time within the recent window. This is the "you have a new kind
 * of error" signal (Sentry's most valuable alert), evaluated at snapshot time so
 * it never runs on the request/exception thread.
 *
 * The window defaults to the alert throttle window, so combined with the per-key
 * throttle each new issue alerts exactly once and then ages out of the window.
 */
class NewIssueRule implements AlertRule
{
    public function evaluate(): iterable
    {
        if (! config('vigilance.alerts.rules.new_issue.enabled', true) || ! config('vigilance.issues.enabled', true)) {
            return;
        }

        $window = (int) (config('vigilance.alerts.rules.new_issue.window_minutes')
            ?? config('vigilance.alerts.throttle_minutes', 15));
        $limit = (int) config('vigilance.alerts.rules.new_issue.limit', 10);
        $cutoff = Carbon::now()->subMinutes(max(1, $window));

        $groups = FailureGroup::query()
            ->whereNull('resolved_at')
            ->where('first_seen_at', '>=', $cutoff)
            ->where(fn ($q) => $q->whereNull('muted_until')->orWhere('muted_until', '<=', Carbon::now()))
            ->orderByDesc('first_seen_at')
            ->limit(max(1, $limit))
            ->get();

        foreach ($groups as $group) {
            yield new Alert(
                key: 'issue_new:'.$group->id,
                title: 'New issue: '.$this->label($group),
                message: $this->summary($group),
                level: 'warning',
            );
        }
    }

    protected function label(FailureGroup $group): string
    {
        return $group->name ?: $group->exception_class ?: 'error';
    }

    protected function summary(FailureGroup $group): string
    {
        $prefix = $group->exception_class ? $group->exception_class.': ' : '';
        $source = $group->source ?: $group->type ?: 'app';

        return $prefix.Str::limit((string) $group->message, 140)." (source: {$source})";
    }
}
