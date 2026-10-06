<?php

namespace Vigilance\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Vigilance\Enums\RunStatus;

/**
 * A job carrying the nested objects real jobs carry — a collection, a date and
 * a backed enum — which a class-restricted unserialize cannot restore.
 */
class NestedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Collection $items,
        public Carbon $due,
        public RunStatus $status,
    ) {}

    public function handle(): void
    {
        // no-op
    }
}
