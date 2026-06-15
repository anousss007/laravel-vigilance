<?php

namespace Vigilance\Capture;

use Illuminate\Support\Carbon;
use Vigilance\Events\FailureRecorded;
use Vigilance\Models\FailureGroup;
use Vigilance\Support\FailureSignature;

/**
 * Fingerprints failures and upserts a FailureGroup so repeated occurrences of
 * the same error collapse into one row with an occurrence count and last-seen
 * timestamp (Sentry-style grouping).
 */
class FailureGrouper
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $type,
        ?string $name,
        ?string $exceptionClass,
        ?string $message,
        ?string $source = null,
        ?string $sample = null,
        array $context = [],
    ): int {
        $signature = FailureSignature::for($type, $name, $exceptionClass, $message);
        $now = Carbon::now();

        $group = FailureGroup::query()->firstOrNew(['signature' => $signature]);

        $isNew = ! $group->exists;

        if (! $group->exists) {
            $group->type = $type;
            $group->name = $name;
            $group->exception_class = $exceptionClass;
            $group->message = $message;
            $group->first_seen_at = $now;
            $group->occurrences = 0;
        }

        $group->last_seen_at = $now;
        $group->occurrences = (int) $group->occurrences + 1;
        $group->source = $source ?? $group->source ?? $type;

        // Keep the latest stack-trace sample / request context on the group so
        // the issue detail has something to show even for request errors (which
        // have no captured run row).
        if ($sample !== null) {
            $group->sample = $sample;
        }

        if ($context !== []) {
            $group->context = $context;
        }

        // Re-open a previously resolved group when it recurs, and mark it as a
        // regression so the regression alert and the "regressed" badge fire.
        if ($group->resolved_at !== null) {
            $group->resolved_at = null;
            $group->regressed_at = $now;
        }

        $group->save();

        FailureRecorded::dispatch($group, $isNew);

        return (int) $group->getKey();
    }
}
