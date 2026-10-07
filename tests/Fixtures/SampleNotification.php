<?php

namespace Vigilance\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SampleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $backoff = 30;

    public function __construct(public string $subject = 'hello') {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
