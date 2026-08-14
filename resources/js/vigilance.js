/**
 * Dashboard runtime.
 *
 * Vigilance does NOT ship its own Alpine: Livewire already bundles one, and a
 * second copy would fight it for the same DOM. This entry point only teaches
 * Livewire's Alpine the pieces the vendored BlatUI components rely on — the
 * anchor/focus/collapse plugins, the x-data components, the directives and the
 * magics — and is bundled by esbuild into resources/dist/vigilance.js so the
 * consuming app still needs no npm, no Vite and no build step of its own.
 *
 * `alpine:init` fires at the top of Alpine.start(), before any element is
 * processed, which is late enough for Alpine to exist and early enough for
 * Alpine.plugin() to still take effect.
 */
import { registerBlatUI } from './blatui-core.js';

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    if (!Alpine) {
        // Livewire is what provides Alpine here; if it is somehow absent the
        // dashboard degrades to static markup rather than throwing on boot.
        return;
    }

    // darkMode:false is "hands off dark mode" — the theme store then never
    // touches the `dark` class. The dashboard drives its own light/dark from
    // the pre-paint script in the layout (localStorage key `vigilance-theme`),
    // and Vigilance has one fixed identity, so the store's preset/base/radius
    // dimensions are deliberately left unused.
    registerBlatUI(Alpine, { darkMode: false });
});
