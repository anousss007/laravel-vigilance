<div>
    @if (count($pending) > 0)
        {{-- Persistent and page-independent on purpose: staging only helps if
             you can walk away, look at something else, and still find what you
             queued up waiting for you. --}}
        <x-vigilance::ui.alert class="border-info/40 bg-info/10">
            <x-vigilance::ui.alert-title class="text-info">
                {{ count($pending) }} change(s) waiting to be applied
            </x-vigilance::ui.alert-title>
            <x-vigilance::ui.alert-description>
                <ul class="mt-1 space-y-1">
                    @foreach ($pending as $index => $change)
                        <li class="flex items-center justify-between gap-3">
                            <span class="font-mono text-xs">{{ $change['label'] }}</span>
                            <button type="button" wire:click="discard({{ $index }})"
                                    class="v-link text-xs">remove</button>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-vigilance::ui.alert-dialog>
                        <x-vigilance::ui.alert-dialog-trigger>
                            <x-vigilance::ui.button size="sm">Apply all</x-vigilance::ui.button>
                        </x-vigilance::ui.alert-dialog-trigger>

                        <x-vigilance::ui.alert-dialog-content>
                            <x-vigilance::ui.alert-dialog-header>
                                <x-vigilance::ui.alert-dialog-title>
                                    Apply {{ count($pending) }} change(s)?
                                </x-vigilance::ui.alert-dialog-title>
                                <x-vigilance::ui.alert-dialog-description>
                                    These run against the live fleet, in the order listed. They take
                                    effect immediately and cannot be rolled back as a batch.
                                </x-vigilance::ui.alert-dialog-description>
                            </x-vigilance::ui.alert-dialog-header>
                            <x-vigilance::ui.alert-dialog-footer>
                                <x-vigilance::ui.alert-dialog-cancel>Cancel</x-vigilance::ui.alert-dialog-cancel>
                                <x-vigilance::ui.alert-dialog-action wire:click="apply">Apply</x-vigilance::ui.alert-dialog-action>
                            </x-vigilance::ui.alert-dialog-footer>
                        </x-vigilance::ui.alert-dialog-content>
                    </x-vigilance::ui.alert-dialog>

                    <x-vigilance::ui.button size="sm" variant="ghost" wire:click="clear">
                        Discard all
                    </x-vigilance::ui.button>
                </div>
            </x-vigilance::ui.alert-description>
        </x-vigilance::ui.alert>
    @endif
</div>
