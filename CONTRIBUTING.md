# Contributing

Thanks for considering a contribution to Vigilance!

## Development setup

```bash
git clone https://github.com/anousss007/vigilance
cd vigilance
composer install
```

## Quality gates

All of these run in CI and must pass:

```bash
composer test       # Pest test suite
composer lint       # Laravel Pint (style check)
composer analyse    # PHPStan / Larastan static analysis
```

Auto-fix style before committing:

```bash
composer format
```

## Looking at the dashboard

Vigilance ships a UI, and a test suite cannot see it. `composer visual` boots a
throwaway Laravel app (`workbench/`) seeded with a plausible week of traffic,
then walks every dashboard page in both themes at desktop and phone width,
screenshots each one, and reports the layout faults it can detect — a table
whose cells the stylesheet never reached, content clipped by a box that cannot
scroll, a page that scrolls sideways, a lazy card stuck on its skeleton, a
failing request.

```bash
npx playwright install chromium   # once
composer visual                   # → visual/output/index.html
```

**The clean report is the floor, not the goal.** The audit only catches faults
it already knows about; open the contact sheet and read the screenshots. Almost
everything worth fixing in the last visual pass — a chart whose five series were
five shades of the same green, an action column that covered the table on a
phone, badges whose closing tags were in the wrong place — was found by looking,
not by the checks.

Useful while chasing one of them:

```bash
node visual/probe.mjs /issues 1440   # measure a page's tables at a given width
node visual/probe-net.mjs /apm       # print failing requests with their bodies
```

The workbench app is dev-only and ships in no release. Data lives in
`workbench/database/database.sqlite`; `composer build` re-migrates and re-seeds
it. When a page renders its empty state in a screenshot, that is usually the
seeder not matching what the recorder really writes — fix the seeder, since a
page fed impossible data is a page nobody has actually reviewed.

## Dashboard CSS

The dashboard ships a precompiled, self-contained Tailwind stylesheet at
`resources/dist/vigilance.css` (no CDN, no build step for consumers). If you
change Blade views or their classes, rebuild and commit it:

```bash
npm install
npm run build
```

The stylesheet is Tailwind **v4** (CSS-first: the theme lives in
`resources/css/vigilance.css`, there is no `tailwind.config.js`).

## Dashboard components

The UI kit is vendored from [BlatUI](https://github.com/anousss007/blatui) into
`resources/views/components/ui/` and used as `<x-vigilance::ui.card>` — the
namespace matters, because a consuming app may have its own `<x-ui.card>` and
the two must never collide.

Only the **static** components are vendored. Anything requiring Alpine is out:
Livewire already ships its own Alpine and a second copy would conflict, and
Vigilance has no JS bundler. Native `<input>`/`<select>`/`<textarea>` controls
get the same look from the `.blat-input` / `.blat-select` / `.blat-checkbox`
classes, no JS involved.

Both kits read the same tokens, so the dashboard stays coherent while views are
migrated one at a time. `blatui` is a dev dependency purely as the upstream to
diff against.

## Guidelines

- Add or update tests for any behavioral change.
- Keep capture code defensive — monitoring must never break the host app.
- Match the existing code style (Pint enforces it).
- Update the `CHANGELOG.md` under `Unreleased`.

## Reporting issues

Open an issue with a minimal reproduction, your Laravel/PHP versions, and the
queue driver in use.
