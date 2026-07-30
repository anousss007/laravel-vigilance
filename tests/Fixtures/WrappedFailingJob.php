<?php

namespace Vigilance\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogicException;
use RuntimeException;

/**
 * Fails with a wrapper (LogicException) whose real cause is a RuntimeException,
 * to exercise root-cause unwrapping on the queue failure path.
 */
class WrappedFailingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public function handle(): void
    {
        try {
            throw new RuntimeException('the real cause on null');
        } catch (RuntimeException $e) {
            throw new LogicException('wrapper hides the cause', 0, $e);
        }
    }
}
