<?php

namespace Vigilance\Apm\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Lottery;
use Illuminate\Support\Sleep;
use Vigilance\Apm\Contracts\Ingest;
use Vigilance\Apm\Contracts\Storage;

/**
 * Drains the APM ingest stream into storage. Only relevant for the write-behind
 * 'redis' ingest driver — for the default 'storage' driver, digest() is a no-op
 * and this command idles. Run one per app (like a queue worker).
 */
class WorkCommand extends Command
{
    /** @var string */
    protected $signature = 'vigilance:apm-work {--once : Digest a single batch then exit}';

    /** @var string */
    protected $description = 'Digest buffered APM telemetry into storage (write-behind ingest).';

    public function handle(Ingest $ingest, Storage $storage): int
    {
        if (! config('vigilance.apm.enabled', true)) {
            $this->components->warn('APM is disabled (vigilance.apm.enabled). Nothing to digest.');

            return self::SUCCESS;
        }

        $this->components->info('Vigilance APM worker started. Press Ctrl+C to stop.');

        while (true) {
            $processed = $ingest->digest($storage);

            // Trim storage occasionally, off the request path.
            Lottery::odds(...$this->trimOdds())->winner(fn () => $storage->trim())->choose();

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            // Back off a little when there was nothing to do.
            Sleep::for($processed > 0 ? 250 : 1000)->milliseconds();
        }
    }

    /** @return array{0:int,1:int} */
    protected function trimOdds(): array
    {
        $odds = (array) config('vigilance.apm.ingest.trim.lottery', [1, 1000]);

        return [(int) ($odds[0] ?? 1), (int) ($odds[1] ?? 1000)];
    }
}
