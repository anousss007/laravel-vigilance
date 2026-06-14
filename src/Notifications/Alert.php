<?php

namespace Vigilance\Notifications;

/**
 * A single alert produced by a rule. "key" is the throttle/dedup key, "level"
 * is one of info|warning|critical.
 */
class Alert
{
    public function __construct(
        public string $key,
        public string $title,
        public string $message,
        public string $level = 'warning',
    ) {}
}
