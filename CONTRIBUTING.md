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

## Dashboard CSS

The dashboard ships a precompiled, self-contained Tailwind stylesheet at
`resources/dist/vigilance.css` (no CDN, no build step for consumers). If you
change Blade views or their classes, rebuild and commit it:

```bash
npm install
npm run build
```

## Guidelines

- Add or update tests for any behavioral change.
- Keep capture code defensive — monitoring must never break the host app.
- Match the existing code style (Pint enforces it).
- Update the `CHANGELOG.md` under `Unreleased`.

## Reporting issues

Open an issue with a minimal reproduction, your Laravel/PHP versions, and the
queue driver in use.
