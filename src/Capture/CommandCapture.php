<?php

namespace Vigilance\Capture;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

class CommandCapture
{
    public function __construct(protected Recorder $recorder) {}

    public function register(): void
    {
        $events = app('events');

        $events->listen(CommandStarting::class, function (CommandStarting $event) {
            $name = $event->command ?: ($event->input->getArguments()['command'] ?? 'default');

            $this->recorder->commandStarting(
                (string) $name,
                $this->safeArguments($event),
                $this->safeOptions($event),
            );
        });

        $events->listen(CommandFinished::class, function (CommandFinished $event) {
            $name = $event->command ?: ($event->input->getArguments()['command'] ?? 'default');

            $this->recorder->commandFinished((string) $name, (int) ($event->exitCode ?? 0));
        });
    }

    /** @return array<string, mixed> */
    protected function safeArguments(CommandStarting|CommandFinished $event): array
    {
        try {
            return $event->input->getArguments();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    protected function safeOptions(CommandStarting|CommandFinished $event): array
    {
        try {
            return $event->input->getOptions();
        } catch (\Throwable) {
            return [];
        }
    }
}
