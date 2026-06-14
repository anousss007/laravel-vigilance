<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Notifications\Events\NotificationSent;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\Sampling;

/**
 * Records notification sends, keyed by channel (mail / database / slack / …),
 * powering the "notifications by channel" card.
 */
class Notifications extends Recorder
{
    use Ignores;
    use Sampling;

    /** @var list<class-string> */
    public array $listen = [NotificationSent::class];

    public function record(NotificationSent $event): void
    {
        $now = time();
        $channel = (string) $event->channel;

        $this->apm->lazy(function () use ($now, $channel) {
            if (! $this->shouldSample() || $this->shouldIgnore($channel)) {
                return;
            }

            $this->apm->record('notification', $channel, null, $now)->count();
        });
    }
}
