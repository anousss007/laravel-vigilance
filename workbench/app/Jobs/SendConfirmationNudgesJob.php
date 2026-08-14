<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A stand-in for a real application job. It exists so the Dispatch page has
 * something to reflect a form from — the page renders one field per
 * constructor parameter, which is exactly the part worth looking at.
 */
class SendConfirmationNudgesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public int $reservationId,
        public string $locale = 'fr',
        public bool $force = false,
    ) {}

    public function handle(): void
    {
        // no-op in the workbench
    }
}
