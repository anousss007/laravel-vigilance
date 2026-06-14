<?php

namespace Vigilance\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Vigilance\Contracts\ShouldNotBeDispatchedManually;

/**
 * A queued job that opts out of the manual dispatch surface even under
 * "discover" mode.
 */
class HiddenJob implements ShouldNotBeDispatchedManually, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        // no-op
    }
}
