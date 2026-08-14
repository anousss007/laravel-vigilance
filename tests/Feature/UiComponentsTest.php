<?php

use Illuminate\Support\Facades\Blade;

/**
 * The dashboard's component kit is vendored from BlatUI into the package itself
 * rather than pulled from the consuming app: Vigilance ships a prebuilt
 * stylesheet and must keep working in an app with no Vite, no npm and no
 * Tailwind of its own. These cover the wiring that makes that possible.
 */
/** @return list<string> */
function vendoredComponents(): array
{
    $files = glob(__DIR__.'/../../resources/views/components/ui/*.blade.php') ?: [];

    expect($files)->not->toBeEmpty();

    return $files;
}

it('renders a vendored component under the vigilance namespace', function () {
    $html = Blade::render('<x-vigilance::ui.card class="p-2">body</x-vigilance::ui.card>');

    expect($html)->toContain('data-slot="card"')
        ->toContain('body');
});

it('resolves classes through tailwind-merge so a call site can override a default', function () {
    // card's default padding is p-6; the call site passes p-2 and must win —
    // this is the whole reason tailwind-merge is a runtime dependency.
    $html = Blade::render('<x-vigilance::ui.card class="p-2">x</x-vigilance::ui.card>');

    expect($html)->toContain('p-2')
        ->not->toContain('p-6');
});

it('carries the five status tones the dashboard needs', function (string $tone, string $expected) {
    // These map one-for-one onto the v-pill.is-* states being replaced, so a
    // migrated view keeps the same semantics it had before.
    $html = Blade::render('<x-vigilance::ui.badge tone="'.$tone.'">x</x-vigilance::ui.badge>');

    expect($html)->toContain('data-slot="badge"')
        ->toContain($expected);
})->with([
    ['success', 'text-success'],
    ['warning', 'text-warning'],
    ['danger', 'text-destructive'],
    ['info', 'text-info'],
    ['neutral', 'text-muted-foreground'],
]);

it('renders a button as a link when given an href', function () {
    $html = Blade::render('<x-vigilance::ui.button href="/x">go</x-vigilance::ui.button>');

    expect($html)->toContain('<a')
        ->toContain('href="/x"')
        ->not->toContain('type="button"');
});

it('keeps every vendored component free of an icon dependency', function () {
    // Upstream reaches for blade-lucide-icons; those glyphs are inlined here
    // instead, so installing Vigilance never drags in an icon package.
    foreach (vendoredComponents() as $file) {
        expect((string) file_get_contents($file))->not->toContain('<x-lucide');
    }
});

it('namespaces every reference a component makes to its siblings', function () {
    // A bare <x-ui.menu-item> would resolve against the *consuming app's*
    // components — or nothing at all. Every internal reference has to carry the
    // vigilance:: namespace or the component silently renders empty.
    foreach (vendoredComponents() as $file) {
        expect((string) file_get_contents($file))->not->toContain('<x-ui.');
    }
});

it('never bundles a second copy of Alpine', function () {
    // Interactive components need Alpine, but Livewire already ships one and
    // two instances fight over the same DOM. The bundle carries only plugins.
    $js = (string) file_get_contents(__DIR__.'/../../resources/dist/vigilance.js');

    expect($js)->not->toContain('alpine:initializing')
        ->and($js)->toContain('alpine:init');
});

it('renders an interactive component with its Alpine wiring intact', function () {
    $html = Blade::render(<<<'BLADE'
        <x-vigilance::ui.dialog>
            <x-vigilance::ui.dialog-trigger>open</x-vigilance::ui.dialog-trigger>
            <x-vigilance::ui.dialog-content>
                <x-vigilance::ui.dialog-title>Confirm</x-vigilance::ui.dialog-title>
            </x-vigilance::ui.dialog-content>
        </x-vigilance::ui.dialog>
    BLADE);

    expect($html)->toContain('x-data')
        ->toContain('data-slot="dialog"')
        ->toContain('Confirm');
});

it('renders a dropdown menu through its delegated menu components', function () {
    // dropdown-menu-item delegates to menu-item; this is the case the namespace
    // rewrite above exists to protect.
    $html = Blade::render(<<<'BLADE'
        <x-vigilance::ui.dropdown-menu>
            <x-vigilance::ui.dropdown-menu-trigger>menu</x-vigilance::ui.dropdown-menu-trigger>
            <x-vigilance::ui.dropdown-menu-content>
                <x-vigilance::ui.dropdown-menu-item>Retry</x-vigilance::ui.dropdown-menu-item>
            </x-vigilance::ui.dropdown-menu-content>
        </x-vigilance::ui.dropdown-menu>
    BLADE);

    expect($html)->toContain('Retry')
        ->toContain('data-slot');
});

it('keeps the compiled stylesheet self-contained', function () {
    $css = (string) file_get_contents(__DIR__.'/../../resources/dist/vigilance.css');

    // No external fetch of any kind: the dashboard has to render offline and
    // behind a strict CSP.
    expect($css)->not->toMatch('#@import\s+url\(#')
        ->not->toContain('https://fonts.')
        ->and($css)->toContain('--v-accent');
});
