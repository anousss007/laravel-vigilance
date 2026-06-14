<?php

namespace Vigilance\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Queued wrapper that runs an artisan command on a worker. Used when the
 * dashboard "run command" form is submitted with the "queue" option so a
 * long-running command does not block the request. The command's own
 * CommandStarting/Finished events are captured by Vigilance as a Command run;
 * this job itself is captured as a Job run.
 */
class RunArtisanCommand implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public array $options = [],
    ) {}

    public function handle(): void
    {
        $input = $this->arguments;

        foreach ($this->options as $key => $value) {
            $input['--'.ltrim($key, '-')] = $value;
        }

        Artisan::call($this->name, $input);
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['vigilance:command-runner', 'command:'.$this->name];
    }
}
