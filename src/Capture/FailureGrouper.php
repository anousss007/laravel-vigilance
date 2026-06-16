<?php

namespace Vigilance\Capture;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vigilance\Events\FailureRecorded;
use Vigilance\Models\FailureGroup;
use Vigilance\Support\FailureSignature;
use Vigilance\Vigilance;

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
        $release = Vigilance::currentRelease();

        // Race-safe create. Under a failing-job storm, many workers record the
        // same signature at once: createOrFirst() leans on the unique "signature"
        // index so they converge on one row instead of inserting duplicates.
        $group = FailureGroup::createOrFirst(
            ['signature' => $signature],
            [
                'type' => $type,
                'name' => $name,
                'exception_class' => $exceptionClass,
                'message' => $message,
                'first_seen_at' => $now,
                'first_release' => $release,
                'occurrences' => 0,
                'source' => $source ?? $type,
            ],
        );

        $isNew = $group->wasRecentlyCreated;

        // Latest-wins metadata (sample / request context / regression re-open).
        // A race here only changes which sample is kept — never the count — so
        // the model save is fine. Crucially it does NOT touch "occurrences": a
        // read-modify-write on the counter loses increments under concurrency.
        $group->last_seen_at = $now;
        $group->source = $source ?? $group->source ?? $type;

        if ($sample !== null) {
            $group->sample = $sample;
        }

        if ($context !== []) {
            $group->context = $context;
        }

        // Re-open a previously resolved group when it recurs, and mark it as a
        // regression (with the release it came back in) so the regression alert
        // and the "regressed" badge fire.
        if ($group->resolved_at !== null) {
            $group->resolved_at = null;
            $group->regressed_at = $now;
            $group->regressed_release = $release;
        }

        $group->save();

        // The occurrence count is the one value that must survive concurrency, so
        // bump it with an atomic SQL increment (DB-serialized per row) rather than
        // writing a value read into PHP — otherwise simultaneous failures clobber
        // each other and the count drifts low.
        FailureGroup::query()->whereKey($group->getKey())->update([
            'occurrences' => DB::raw('occurrences + 1'),
        ]);

        // Reflect the increment in the in-memory model the event carries (the
        // authoritative value lives in the DB; listeners that need an exact count
        // re-read it).
        $group->occurrences = (int) $group->occurrences + 1;

        FailureRecorded::dispatch($group, $isNew);

        return (int) $group->getKey();
    }
}
