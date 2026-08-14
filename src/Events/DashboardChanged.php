<?php

namespace Vigilance\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\InteractsWithTime;
use Throwable;

/**
 * "Something the dashboard shows has changed."
 *
 * Deliberately carries no payload beyond a topic. The dashboard's pages each
 * read their own data; all they need is a nudge to re-read it. Shipping the
 * changed rows over the socket would mean every page subscribing to a payload
 * shape it mostly ignores.
 *
 * Throttled at the dispatch site rather than here-and-there: broadcasting once
 * per finished job would be far more traffic than the polling it replaces, so
 * a topic can fire at most once per interval and the dashboards coalesce.
 */
class DashboardChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithTime;

    public function __construct(public string $topic = 'runs') {}

    /**
     * Dispatch at most once per throttle window for this topic.
     *
     * A busy queue finishes thousands of jobs a minute; the dashboard only
     * needs to know that *some* finished. Without this, "real time" would cost
     * strictly more than the 5-second poll it is meant to replace.
     */
    public static function throttled(string $topic = 'runs'): void
    {
        if (! config('vigilance.realtime.enabled', false)) {
            return;
        }

        try {
            $seconds = max(1, (int) config('vigilance.realtime.throttle_seconds', 3));

            if (Cache::add('vigilance:realtime:'.$topic, true, $seconds)) {
                static::dispatch($topic);
            }
        } catch (Throwable) {
            // Monitoring must never break the unit of work that triggered it.
        }
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel((string) config('vigilance.realtime.channel', 'vigilance'))];
    }

    public function broadcastAs(): string
    {
        return 'vigilance.changed';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['topic' => $this->topic];
    }
}
