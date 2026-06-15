# Releasing Vigilance

A release is **not** "the code works." It is the code, the version strings, the
changelog, the user-facing docs, the AI-assistant integration, and the
verification — all moved forward together. This checklist exists so none of it is
kept in anyone's head. Work top to bottom; don't skip a section because "it
probably didn't change."

> Versioning: [SemVer](https://semver.org). `patch` = fixes/docs/no API change ·
> `minor` = backwards-compatible features · `major` = breaking. Tags are
> `vX.Y.Z`; Packagist publishes from the tag.

## 1. Code is green

- [ ] `php vendor/bin/pint --test` — code style passes
- [ ] `php vendor/bin/phpstan analyse --memory-limit=512M --no-progress` — no errors
- [ ] `php vendor/bin/pest` — full suite passes
- [ ] (recommended) Fresh-install smoke test: install the package into a clean
      Laravel app via a path repo, `vigilance:install`, `migrate`, and confirm the
      dashboard pages render and `vigilance:doctor` is green.

## 2. Bump the version (every place — there is no single source)

- [ ] `src/Vigilance.php` → `public static string $version` (the canonical value;
      `php artisan about`, `vigilance:doctor`, the dashboard footer all read it)
- [ ] `docs/index.html` → the nav brand `v0.0.0` **and** the hero eyebrow `v0.0.0`
- [ ] `composer.json` has **no** `version` field (Packagist is tag-driven) — keep it that way

## 3. Changelog

- [ ] Move the new notes from `## [Unreleased]` into `## [X.Y.Z] - YYYY-MM-DD`
      ([Keep a Changelog](https://keepachangelog.com): Added / Changed / Fixed / Removed)
- [ ] If the schema changed in the **base migration** (the package folds schema
      into one migration), add a "Schema note" telling existing installs to run
      `php artisan migrate:fresh`

## 4. User-facing docs (update for any new/changed feature)

- [ ] `README.md` — comparison table, feature sections, config highlights, alerting/channels
- [ ] `docs/index.html` — feature cards, comparison table, sections, nav/footer, `<meta>` descriptions
- [ ] `docs/*.md` — the relevant guide (`apm.md`, `tracing.md`, `observability.md`); add a new guide for a whole new area
- [ ] `config/vigilance.php` — every new option documented inline

## 5. Laravel Boost integration (ships in the package — keep it current)

- [ ] `resources/boost/guidelines/core.blade.php` — conventions + snippets for new features/APIs/env vars
- [ ] `resources/boost/skills/vigilance-development/SKILL.md` — frontmatter description, sections, gotchas, commands table

## 6. Accessibility (for any new/changed dashboard page)

- [ ] axe-core: **0 violations on desktop AND mobile** for every new/changed page
      (seed the playground, audit each page in both viewports)

## 7. Ship it

- [ ] Commit on `main`; push; confirm **CI is green** for the release commit
- [ ] `git tag -a vX.Y.Z -m "vX.Y.Z — <headline>"` && `git push origin vX.Y.Z`
- [ ] `gh release create vX.Y.Z --title "vX.Y.Z — <headline>" --notes-file <notes> --latest`
- [ ] Verify **Packagist** synced the tag (`repo.packagist.org/p2/anousss007/vigilance.json`)
- [ ] Verify the **docs site** redeployed (the `pages-build-deployment` workflow is green)

## Commit/PR conventions

- No AI-attribution trailers (`Co-Authored-By`, "Generated with …") in commits or PRs.
- Push directly to `main`; tags drive the release.
