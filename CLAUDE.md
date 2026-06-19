# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## State of the project

Laravel 13 storefront for **2POROGA / Avtoporogi** (car body sills & arches shop). Current work is **frontend-only Blade markup** of the homepage from a Figma design — the backend is built separately by someone else, so don't add controllers/models/DB logic unless asked. Build reusable Blade components and match the Figma layout.

## Frontend architecture

- **No CSS framework** (Tailwind was removed). **SCSS** (`sass`) with design tokens.
- `resources/scss/app.scss` is the entry; it `@use`s partials in order: `variables` → `fonts` → `base`. Add component styles as new `_name.scss` partials `@use`d after `base`.
- **`_variables.scss`** holds all design tokens as SCSS variables (`$color-primary`) and mirrors them to CSS custom properties on `:root` (`--color-primary`). In component styles use `var(--token)`; use `$vars` only where you need compile-time Sass (math, functions). Never hardcode hex/px from the design.
- Single content width: `--container` (1448px). The reviews carousel bleeds past the container via overflow — don't add a wider container token.
- **Naming follows BEM** to mirror the Figma layer names (e.g. `header__phone`, `header__link`).
- **Fonts:** self-hosted `.ttf` in `public/fonts/` (Gilroy + Montserrat), wired via `@font-face` in `_fonts.scss` using absolute `/fonts/...` URLs (served straight from the web root, outside Vite). Golos Text loads via Bunny Fonts (`vite.config.js`). Gilroy is commercial — licensing is the owner's responsibility.
- Blade: layout at `resources/views/layouts/app.blade.php` (yields `header`/`content`/`footer`), page at `resources/views/home.blade.php`, route in `routes/web.php`.

## Stack

- PHP `^8.3`, Laravel `^13.8`
- **SQLite** database (`DB_CONNECTION=sqlite`, file at `database/database.sqlite`)
- **Pest 4** for tests (not PHPUnit's class style, despite `phpunit.xml`)
- **Vite 8** + `laravel-vite-plugin` for assets and hot reload (`refresh` watches `resources/**` and `routes/**`)
- **Pint** for code style (default Laravel preset, no `pint.json` override)

## Commands

```bash
composer dev          # Run server + queue listener + log tailer (pail) + vite, all concurrently
composer test         # Clear config, then run the full Pest suite
composer setup        # One-time: install deps, create .env, key:generate, migrate, build assets

./vendor/bin/pest                              # Run tests directly
./vendor/bin/pest tests/Feature/ExampleTest.php # Run a single test file
./vendor/bin/pest --filter='it does something' # Run tests matching a name
./vendor/bin/pint                              # Format code (Laravel preset)
./vendor/bin/pint --test                       # Check formatting without writing

php artisan migrate          # Apply migrations
php artisan migrate:fresh     # Drop all tables and re-migrate
php artisan tinker            # REPL
php artisan pail              # Tail application logs
```

`composer dev` is the normal way to run the app locally — it launches `php artisan serve`, the queue worker, the log tailer, and Vite together and kills all of them on exit.

## Testing notes

- Tests are written in Pest's functional style (`it(...)`, `test(...)`), not as PHPUnit method classes. Match that style for new tests.
- Two suites: `tests/Unit` and `tests/Feature`. Feature tests boot the framework via `tests/TestCase.php`; configure global test behavior in `tests/Pest.php`.
- `composer test` runs `config:clear` first — config caching can otherwise mask `.env` changes during tests.
