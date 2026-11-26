# Paymenter — tester-env deployment

Source-built RL environment for the pinned upstream baseline.

| | |
|---|---|
| Upstream | https://github.com/Paymenter/Paymenter |
| Baseline | tag `v1.5.9` = `ee729f7d99ac18942625b79f3f93d8d8b21255e8` |
| Fork | https://github.com/sma-rl-dev/paymenter (branch `tester-env-baseline`) |
| Host port | 8099 (only published port) |
| Admin | `admin@example.com` / `TesterEnv123!` (`/admin`, via `/login`) |
| Clients | `amelia.hart@example.com` / `ClientPass123!`, `daniel.okafor@example.com` / `ClientPass123!` |
| Browser origin | `http://host.docker.internal:8099/` |

Tooling in this repo: `tester-env`, `docker-compose.tester-env.yml`,
`tester-env-seed.php`. See `docs/APP_CLI_PATTERN.md` in the corpus for the CLI
contract.

## Quick Start

```bash
./tester-env deploy    # build image from source, start app + MariaDB + Redis, migrate, seed, verify
./tester-env seed      # ensure deterministic admin, (re)apply tester-env-seed.php, verify
./tester-env verify    # pages, seed counts, shop-page content
./tester-env reset     # compose down -v (containers + volumes removed, image kept)
./tester-env stop      # stop containers, keep volumes
```

`--run-id <id>` and `--port <port>` isolate a second stack (compose project,
container names and all three volumes get the suffix). `IMAGE_TAG` is honoured
verbatim and never contains `RUN_ID`, so identical baselines share an image.
`APP_URL` defaults to `TESTER_ENV_BROWSER_ORIGIN`, then
`http://host.docker.internal:$PORT` (manual runs).

## Topology

Three services, app built from this checkout — upstream's
`docker-compose.example.yml` prebuilt `ghcr.io/paymenter/paymenter:latest`
image reference is overridden with `build: .` against the upstream
multi-stage Dockerfile (`php:8.3-fpm-alpine` composer stages + `node:22-alpine`
`npm run build` stage, nginx/supervisor/dcron, entrypoint
`.github/docker/entrypoint.sh` serving port 80):

- `paymenter`: built from this checkout, only published port (`8099:80`).
- `database`: `mariadb:lts`, no published port.
- `cache`: `redis:alpine`, no published port.
- Volumes: `paymenter-db`, `paymenter-var` (`/app/var`, the `.env` symlink
  target), `paymenter-storage` (`/app/storage`). All are RUN_ID-suffixed and
  all are dropped by `reset`.

Deliberate deviations from the example compose: no `app:/app` named volume and
no host bind-mounts of `./themes` / `./extensions`, so rebuilt (mutant) images
take effect on restart. `PAYMENTER_SKIP_DEFAULT=true` so theme/extension mutant
edits survive restarts (first boot still populates empty dirs from the baked-in
defaults).

## Determinism

- `APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` are pinned (top of `tester-env`;
  the entrypoint generates a random key when unset, which would invalidate
  sessions/gateway credentials across runs).
- Entrypoint runs `php artisan migrate --seed --force` on every boot; the
  upstream seeder (admin Role with all permissions, default settings, USD
  currency, email templates) is randomness-free.
- Admin is created non-interactively
  (`php artisan app:user:create Tester Admin admin@example.com TesterEnv123! <admin-role-id>`,
  idempotent) with `email_verified_at` backfilled, because `MustVerifyEmail`
  gates `/dashboard`, `/invoices`, `/tickets` and `/services`.
- The seeded `settings.app_url` (default `http://localhost`) overrides
  `config('app.url')` via `SettingsProvider::forceRootUrl`, so `deploy`
  converges it to the browser-reachable origin (`APP_URL`) with tinker and
  flushes the settings cache. Without this, assets/redirects root at
  `http://localhost` (no port) and the app renders blank in the browser.
  There is no `/admin/login` route (Filament panel has no `->login()`); the
  correct flow is log in at `/login`, then visit `/admin` (guests 302 there).
- `settings.captcha=disabled` is seeded, so browser login needs no captcha;
  `settings.mail_disable=1`, so no external email leaves the stack.

## Seed profile (via `./tester-env seed`)

`tester-env-seed.php` is piped host-side into `php artisan tinker` (no image
rebuild needed to change seed data) and converges with `updateOrCreate` /
`firstOrNew` plus fixed timestamps (service end-states pinned via the query
builder to bypass `now()`-based renewal and status-flip listeners):

- Categories: Cloud Hosting, Web Hosting. Products: Starter VPS (Monthly $9.99,
  Yearly $99.99), Business VPS (Monthly $24.99), Shared Starter (Monthly $4.99),
  plus hidden Shared Legacy.
- Clients (verified): Amelia Hart (`amelia.hart@example.com`), Daniel Okafor
  (`daniel.okafor@example.com`), both `ClientPass123!`.
- 2 orders; service active (Amelia, expires 2027-02-01) + pending (Daniel).
- Invoices: paid INV-1 ($9.99, remaining 0) + pending INV-2 ($4.99, due
  2027-02-10). Tickets: replied `Cannot SSH into my VPS` (2 messages) + closed
  `Question about renewal date` (2 messages). Coupon `WELCOME10`.
- `./tester-env verify` asserts `GET /`, `/login`, `/admin` (200/302), the exact
  DB counts (`categories 2, products 4, plans 4, prices 4, users 3, orders 2,
  services_active 1, services_pending 1, invoices_paid 1, invoices_pending 1,
  tickets 2, coupons 1`) and shop-page content (category lists Starter VPS,
  product page shows Add to cart).

## Reset semantics

`./tester-env reset` = `docker compose down -v --remove-orphans` (containers +
all three named volumes; image kept). Reset + deploy + seed + verify reproduces
identical counts and INV-1/INV-2 numbers. Never touches
`alevern/chromium-browser` or running `chromium-ls*` containers.

## Measured costs (2026-11-26)

- Cold build + deploy (composer install + `npm run build`): 5m40s; first-boot
  migrate + seed healthy (`GET /` 200) ~24s after container start.

## Browser evidence

- `GET /` 200 with assets absolute to the browser origin (css 200/114KB, js
  200/281KB); zero `http://localhost` refs when requested with
  `Host: host.docker.internal:8099`.
- `/admin` 302 to the login page for guests; `/login` 200 with the auth Livewire
  email/password form.
- Seeded UI (browser-verified): shop categories/products, Amelia's client area
  (dashboard, invoices, tickets, services), admin lists.

## Caveats for mutation work

- Cold builds run `composer install` + `npm run build`; needs network access.
- First boot runs the full migration set plus seeders (slow health gate, ~10 min
  budget in the CLI).
- `themes/` and `extensions/` renew from baked-in defaults unless
  `PAYMENTER_SKIP_DEFAULT=true` (pinned in the overlay); keep it pinned so
  mutant edits survive restarts.
