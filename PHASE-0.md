# Phase 0 — Environment & Tooling

Status: **Complete.** No business logic was implemented (that is Phase 1). This document records what was built, how to run it, and the evidence used to verify it actually works, per the "every phase must be fully documented" rule.

Scope, per ARCHITECTURE.md §12 Phase 0: staging environment, git repo structure, Composer + PHPCS (PSR-12 + WPCS) tooling, CI, and a Linux crontab skeleton pointed at no-op commands.

---

## 1. Decisions locked in before starting

Two items were open questions in ARCHITECTURE.md §13/§9 and were resolved with the user before any work began, since they determine where every subsequent file and git commit lives:

- **Staging location: local Docker environment**, not a VPS subdomain. Zero risk to the production `phimtoico.org` server; no changes were made to it in this phase.
- **Repository structure: single monorepo** containing all six plugins and the theme.

## 2. What was built

### 2.1 Local tooling (this machine)
- Docker Desktop was installed but not running and not on `PATH` — started it and symlinked `docker`, `docker-credential-desktop`, `docker-credential-osxkeychain`, `docker-credential-ecr-login` into `/opt/homebrew/bin`.
- Installed `php@8.3` and `composer` via Homebrew; `php` on `PATH` now explicitly resolves to 8.3.33 (Homebrew also pulled in a newer PHP as a Composer dependency — 8.3 was re-linked on top so it wins).

### 2.2 Git repository
- `git init` at the repo root. `.gitignore` excludes `vendor/`, `node_modules/`, `.env`, Docker/WP runtime state, and editor/OS cruft.

### 2.3 Folder structure
Matches ARCHITECTURE.md §4/§14 exactly:

```
wp-content/plugins/{tube-core,tube-player,tube-search,tube-seo,tube-admin,tube-cache}/
    {includes,tests}/                 (+ migrations/ for tube-core and tube-search)
wp-content/themes/tube-theme/
    inc/, template-parts/, assets/{css,js}/
ops/cron/                              staging.cron + logs/
ops/docker/{nginx,cron}/               container config
.github/workflows/ci.yml
```

Every plugin has a minimal, valid WordPress plugin header (`tube-{name}.php`) and its own `composer.json` with PSR-4 autoloading (`Tube_Core\` → `includes/`, etc.) — no classes exist yet, only the autoload mapping. The theme has a valid `style.css` header and a placeholder `index.php`. All are explicitly commented as "Phase 0 scaffold only" so it's unambiguous where Phase 1 picks up.

The four dependent plugins (`tube-player`, `tube-search`, `tube-seo`, `tube-admin`) declare `Requires Plugins: tube-core` in their headers, per ARCHITECTURE.md §4. `tube-cache` deliberately does not (it's an independent utility, not a `tube-core` dependent).

### 2.4 Composer + PHPCS (PSR-12 + WordPress Coding Standards)
- Root `composer.json`: dev-only tooling (`squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, `phpcsstandards/phpcsutils`, `phpcsstandards/phpcsextra`). This is **not** a shared runtime dependency tree — each plugin is independently `composer install`-able via its own `composer.json`, consistent with "every plugin must be independently testable."
- `phpcs.xml` combines `PSR12` with `WordPress-Extra` + `WordPress-Docs`, targeting PHP 8.3.

**A real, empirically-confirmed conflict** between WordPress's traditional layout conventions and PSR-12 was found and resolved (not assumed) by actually running PHPCS against the stub files:

| Conflict | Resolution |
|---|---|
| Tabs (WordPress) vs. spaces (PSR-12) for indentation | `Generic.WhiteSpace.DisallowSpaceIndent` excluded; PSR-12's space-indent rule governs |
| Padded `if ( ! foo() )` (WordPress) vs. unpadded `if (!foo())` (PSR-12) | `WordPress.WhiteSpace.ControlStructureSpacing.*` and `PEAR.Functions.FunctionCallSignature.Space*Bracket` excluded |
| `WordPress.WhiteSpace.OperatorSpacing` (wants a space after `!`) directly contradicted PSR-12's no-space-after-`(` rule for `if (!defined(...))` | Excluded — confirmed empirically redundant with `PSR12.Operators.OperatorSpacing` for the general case (`$a=1;` is still caught) |
| WPCS's legacy `class-name-like-this.php` file-naming convention vs. this project's Composer PSR-4/StudlyCaps classes | `WordPress.Files.FileName` excluded (per ARCHITECTURE.md §11, this one was decided in advance, not discovered) |

Every exclusion is commented in `phpcs.xml` with its reasoning. All other WordPress-Extra sniffs (escaping, sanitization, i18n, nonce/capability checks, `PrefixAllGlobals` for all six plugin prefixes) are active and unmodified.

**Verified**: `composer install` succeeds; `./vendor/bin/phpcs --standard=phpcs.xml` exits `0` (zero errors, zero warnings) against every current plugin/theme file.

### 2.5 Docker Compose staging stack
Services: `db` (MariaDB 11.4), `redis` (7-alpine), `wordpress` (official `wordpress:php8.3-fpm`), `nginx` (reverse proxy to PHP-FPM, published on `localhost:8080`), `wpcli` (`wordpress:cli-php8.3`, kept alive for `docker compose exec`), and `cron` (custom image built from `wordpress:cli-php8.3` + Alpine's built-in `busybox crond`).

All plugin/theme directories are bind-mounted from the repo into `wp-content/plugins` and `wp-content/themes` in every relevant container, so edits on the host are immediately live — no rebuild needed for PHP changes in Phase 1+.

`DISABLE_WP_CRON` is set to `true` — WP-Cron is never used in this project (ARCHITECTURE.md §7); confirmed via `wp config get DISABLE_WP_CRON` returning `1`.

**Known Docker-only quirk, documented and fixed**: the Alpine-based `wpcli`/`cron` images' `www-data` is uid 82, while the Debian-based `wordpress` image's `www-data` is uid 33. This caused a "wp-config.php is not writable" error the first time `wpcli` tried to edit it. Fixed by running the `wpcli` service as `root` (the `cron` image already ran as root). This is purely a cross-image artifact of local Docker staging, not a real production concern, and is commented as such in `docker-compose.yml`.

### 2.6 Linux crontab skeleton (no-op)
`ops/cron/staging.cron` lists every background job from ARCHITECTURE.md §7 at its correct cadence, each currently invoking a `wp eval` no-op placeholder (since none of the real commands like `tube-core stats:rollup` exist until Phase 1+). Each line comments the real command it will become. The one exception is the WP-Cron safety-net line, which already runs its real command (`wp cron event run --due-now`) since that's a WP-CLI core command, not something a tube-* plugin needs to provide.

### 2.7 CI
`.github/workflows/ci.yml` runs `composer install` + PHPCS on push/PR to `main`. **Not pushed to any remote** — no GitHub remote has been configured for this repo yet; the workflow file is committed locally and will activate whenever one is added.

---

## 3. Verification evidence

All of the following were actually run and observed, not assumed:

| Check | Result |
|---|---|
| `docker compose up -d --build` | All 6 services started; `db`/`redis` report `healthy` |
| `php -v` inside the `wordpress` container | `PHP 8.3.33` |
| Redis reachable from the app network | `nc -zv redis 6379` → open |
| `wp core install` | Succeeded |
| `wp plugin list` | All 6 `tube-*` plugins recognized |
| `wp theme list` | `tube-theme` recognized |
| **Dependency enforcement**: `wp plugin activate tube-player` before `tube-core` is active | **Blocked by WordPress itself**: "Tube Player requires 1 plugin to be installed and activated: Tube Core." — proves `Requires Plugins` isn't just documentation |
| `wp plugin activate tube-core` then the other five, `wp theme activate tube-theme` | All succeeded; final `wp plugin list`/`wp theme list` shows everything `active` |
| `curl http://localhost:8080/` | `HTTP 200` |
| `curl http://localhost:8080/wp-json/` | `HTTP 200` |
| `wp config get DISABLE_WP_CRON` | `1` |
| Cron sidecar logs after ~90s of uptime | `views-flush.log`, `stats-rollup.log`, `import-process.log`, `wp-cron-safety-net.log` all show successful executions on schedule |
| `./vendor/bin/phpcs --standard=phpcs.xml` (full repo) | Exit code `0`, zero errors, zero warnings |

---

## 4. How to run this locally

```bash
cp .env.example .env          # then edit if you want non-default credentials
docker compose up -d --build
docker compose exec wpcli wp core is-installed --allow-root \
  || docker compose exec wpcli wp core install --allow-root \
       --url="http://localhost:8080" --title="Tube Site Staging" \
       --admin_user=staging_admin --admin_password=<choose one> \
       --admin_email=staging@phimtoico.org --skip-email
docker compose exec wpcli wp plugin activate tube-core --allow-root
docker compose exec wpcli wp plugin activate tube-player tube-search tube-seo tube-admin tube-cache --allow-root
docker compose exec wpcli wp theme activate tube-theme --allow-root
```

Site: http://localhost:8080 · REST API: http://localhost:8080/wp-json/

Lint: `composer install && composer run lint` (or `composer run lint:fix` for auto-fixable issues).

Cron logs: `ops/cron/logs/*.log` (gitignored — regenerated locally).

---

## 5. Explicitly out of scope for Phase 0

No CPT, no taxonomies, no database tables, no migration runner, no event dispatcher, no REST routes, no real WP-CLI commands, no theme templates. All of that is Phase 1 onward per ARCHITECTURE.md §12, and none of it was started here, per the "never generate multiple phases together" rule.

## 6. Production impact

None. All work happened in the local Docker staging environment described above. The production server (`root@139.99.96.155:/www/wwwroot/phimtoico.org`) was not accessed or modified during this phase.
