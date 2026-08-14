<div @if ($active) wire:poll.30s @endif class="shrink-0">
    @if (! $available)
        {{-- Capability not enabled; render nothing rather than a dead button. --}}
    @elseif ($active)
        @php $minutesLeft = (int) ceil($secondsLeft / 60); @endphp

        {{-- Compact by necessity: this lives in the topbar flex row, so a
             full-width alert would stretch the bar on every page. Still loud
             enough to be the thing you notice — which is the point, since the
             failure mode being designed against is leaving it on. --}}
        <div class="flex items-center gap-2">
            <x-vigilance::ui.badge tone="warning" title="{{ $engagedBy ? 'Engaged by '.$engagedBy : 'Incident mode' }}">
                <span class="hidden sm:inline">Incident mode &middot;</span>
                {{ $minutesLeft }}m left
            </x-vigilance::ui.badge>
            <x-vigilance::ui.button size="xs" variant="ghost" wire:click="disengage">End</x-vigilance::ui.button>
        </div>
    @else
        <x-vigilance::ui.dialog>
            <x-vigilance::ui.dialog-trigger>
                <x-vigilance::ui.button size="sm" variant="ghost" title="Turn everything up, briefly">
                    <span class="hidden sm:inline">Incident mode</span>
                    <span class="sm:hidden" aria-hidden="true">!</span>
                    <span class="sr-only sm:hidden">Incident mode</span>
                </x-vigilance::ui.button>
            </x-vigilance::ui.dialog-trigger>

            <x-vigilance::ui.dialog-content>
                <x-vigilance::ui.dialog-header>
                    <x-vigilance::ui.dialog-title>Turn everything up, briefly</x-vigilance::ui.dialog-title>
                    <x-vigilance::ui.dialog-description>
                        Keeps every trace, stops sampling anything out and lowers the log floor —
                        then reverts automatically. The timer is the cache entry's TTL, so it
                        expires even if the app is redeployed or nothing runs the scheduler again.
                    </x-vigilance::ui.dialog-description>
                </x-vigilance::ui.dialog-header>

                <label class="blat-label mt-2" for="incident-minutes">Duration (minutes)</label>
                <input id="incident-minutes" class="blat-input" type="number" min="1"
                       max="{{ $maxMinutes }}" wire:model="minutes">
                <p class="text-xs v-muted">Capped at {{ $maxMinutes }} minutes.</p>

                <x-vigilance::ui.dialog-footer>
                    <x-vigilance::ui.dialog-close>Cancel</x-vigilance::ui.dialog-close>
                    <x-vigilance::ui.button wire:click="engage">Engage</x-vigilance::ui.button>
                </x-vigilance::ui.dialog-footer>
            </x-vigilance::ui.dialog-content>
        </x-vigilance::ui.dialog>
    @endif
</div>
