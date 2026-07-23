<?php

namespace Vigilance\Control;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vigilance\Models\FailureGroup;
use Vigilance\Models\Run;

/**
 * Manually merge one error issue into another when the automatic fingerprinting
 * split what is really the same problem. The source's occurrences and runs move
 * to the target, the source is marked merged (and hidden from the inbox), and
 * future occurrences of the source's signature are redirected to the target by
 * FailureGrouper.
 */
class IssueMerger
{
    public function __construct(protected AuditLogger $audit = new AuditLogger) {}

    /**
     * @return array{merged_into: int, moved_runs: int, occurrences: int}
     */
    public function merge(int $fromId, int $intoId, ?string $user = null): array
    {
        if ($fromId === $intoId) {
            throw new \InvalidArgumentException('An issue cannot be merged into itself.');
        }

        $from = FailureGroup::query()->whereKey($fromId)->first();
        $into = FailureGroup::query()->whereKey($intoId)->first();

        if ($from === null || $into === null) {
            throw new \InvalidArgumentException('Both the source and target issue must exist.');
        }

        // Resolve the target through any existing merge chain so we never point
        // at an already-merged group.
        $seen = [];
        while ($into->merged_into !== null && ! in_array($into->id, $seen, true)) {
            $seen[] = $into->id;
            $into = FailureGroup::query()->whereKey($into->merged_into)->first() ?? $into;
        }

        if ($from->id === $into->id) {
            throw new \InvalidArgumentException('The issues are already merged together.');
        }

        $movedRuns = Run::query()->where('failure_group_id', $from->id)->update(['failure_group_id' => $into->id]);

        FailureGroup::query()->whereKey($into->id)->update([
            'occurrences' => DB::raw('occurrences + '.(int) $from->occurrences),
            'last_seen_at' => $into->last_seen_at !== null && $from->last_seen_at !== null
                ? max($into->last_seen_at, $from->last_seen_at)
                : ($from->last_seen_at ?? $into->last_seen_at),
        ]);

        $from->forceFill([
            'merged_into' => $into->id,
            'resolved_at' => $from->resolved_at ?? Carbon::now(),
        ])->save();

        $this->audit->log(
            action: 'merge_issue',
            subject: (string) $from->id,
            meta: ['from' => $from->id, 'into' => $into->id, 'moved_runs' => $movedRuns, 'occurrences' => (int) $from->occurrences],
            user: $user,
        );

        return ['merged_into' => (int) $into->id, 'moved_runs' => (int) $movedRuns, 'occurrences' => (int) $from->occurrences];
    }
}
