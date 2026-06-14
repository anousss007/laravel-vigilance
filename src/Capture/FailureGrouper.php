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
    public function record(string $type, ?string $name, ?string $exceptionClass, ?string $message): int
    {
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

        // Re-open a previously resolved group when it recurs.
        if ($group->resolved_at !== null) {
            $group->resolved_at = null;
        }

        $group->save();

        FailureRecorded::dispatch($group, $isNew);

        return (int) $group->getKey();
    }
}
