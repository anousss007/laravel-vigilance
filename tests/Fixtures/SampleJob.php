<?php

namespace Vigilance\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Vigilance\Contracts\Dispatchable as VigilanceDispatchable;

class SampleJob implements ShouldQueue, VigilanceDispatchable
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $amount = 5,
        public string $label = 'hello',
        public ?string $password = null,
    ) {}

    public function handle(): void
    {
        // no-op
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['sample', 'amount:'.$this->amount];
    }
}
