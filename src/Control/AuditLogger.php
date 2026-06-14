<?php

namespace Vigilance\Control;

use Illuminate\Support\Carbon;
use Vigilance\Models\AuditEntry;

/**
 * Writes audit-trail entries for every manual control action (dispatching a
 * job, running a command, retrying a failed run) so the dashboard can show who
 * did what and when.
 */
class AuditLogger
{
    /** @param array<string, mixed> $meta */
    public function log(
        string $action,
        ?string $subject = null,
        ?int $runId = null,
        array $meta = [],
        ?string $user = null,
    ): void {
        try {
            AuditEntry::query()->create([
                'user' => $user,
                'action' => $action,
                'subject' => $subject,
                'run_id' => $runId,
                'meta' => $meta,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable) {
            // Auditing must never break the action it is recording.
        }
    }
}
