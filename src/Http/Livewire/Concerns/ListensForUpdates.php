<?php

namespace Vigilance\Http\Livewire\Concerns;

/**
 * Refresh a page from a broadcast instead of a timer, when the app has
 * broadcasting set up.
 *
 * Vigilance does not ship Laravel Echo. A monitoring package has no business
 * adding Pusher and its client to every install for a feature most will not
 * turn on — and an app that has broadcasting already loads Echo anyway.
 * Livewire binds `echo-private:` listeners natively as soon as `window.Echo`
 * exists, so this needs no JavaScript of its own; when it is absent, the
 * `wire:poll` fallback in the view keeps working exactly as before.
 */
trait ListensForUpdates
{
    /** @return array<string, string> */
    public function getListeners(): array
    {
        if (! config('vigilance.realtime.enabled', false)) {
            return [];
        }

        $channel = (string) config('vigilance.realtime.channel', 'vigilance');

        return [
            "echo-private:{$channel},.vigilance.changed" => '$refresh',
        ];
    }
}
