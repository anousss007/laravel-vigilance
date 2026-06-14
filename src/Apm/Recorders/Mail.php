<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Mail\Events\MessageSent;
use Throwable;
use Vigilance\Apm\Recorders\Concerns\Groups;
use Vigilance\Apm\Recorders\Concerns\Ignores;
use Vigilance\Apm\Recorders\Concerns\Sampling;

/**
 * Records mail sends, keyed by recipient (grouped). Powers the "mail sent" card
 * — who your app is emailing and how much.
 */
class Mail extends Recorder
{
    use Groups;
    use Ignores;
    use Sampling;

    /** @var list<class-string> */
    public array $listen = [MessageSent::class];

    public function record(MessageSent $event): void
    {
        $now = time();
        $to = $this->firstRecipient($event);

        $this->apm->lazy(function () use ($now, $to) {
            if (! $this->shouldSample() || $this->shouldIgnore($to)) {
                return;
            }

            $this->apm->record('mail', $this->group($to), null, $now)->count();
        });
    }

    protected function firstRecipient(MessageSent $event): string
    {
        try {
            $recipients = $event->sent->getEnvelope()->getRecipients();
            $first = $recipients[0] ?? null;

            return $first !== null ? $first->getAddress() : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
