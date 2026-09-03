# Deploying a New, Independent Site From This Release

A concise, copy-pasteable checklist for cloning this same shared codebase
onto a brand-new domain, sharing the same VPS as an existing site (the
scenario this document was written from: `phimtoico.org` and
`dongtoico.org` coexisting on one VPS). It exists specifically to stop
the setup bugs that were found and fixed the hard way the first time a
second site was cloned — read it in full before starting, not just as a
reference to jump around in.

**This is a new, from-scratch WordPress installation, not a copy of an
existing site's files/database.** Never `cp`/`rsync` an existing site's
`wp-content/uploads`, database, or `wp-config.php` into a new site — see
`docs/DEPLOYMENT.md`'s own "Do NOT copy the live filesystem" guidance,
which applies here for exactly the same reason (runtime files, secrets,
and site-specific state have no place in a fresh clone).

**New sites must be built from GitHub `v1.4.0` or a newer verified
release tag — never by cloning/copying a production site's WordPress
directory.** The tagged release is the single official, cloneable
source of every accepted shared-code fix (R2 support, signed R2
playback, canonical object-key handling and legacy-prefix recovery,
unreachable-key rejection before publish, R2 player sizing, automatic
R2 duration detection, Redis multi-site isolation, the multi-site brand
architecture, and everything else already running in production);
copying a live site's directory instead risks dragging along that
site's own accumulated local drift, half-applied migrations, or
runtime state that was never meant to leave that one site. Each new
site still needs its own independent database, uploads/content, Redis
logical-DB assignment, `wp-config.php`, cron paths, and (optionally)
site brand — see the checklist below — but the *application code*
always comes from GitHub, never from another site's filesystem.

## What must be independent per site (never shared)

- Database (name, user, password) — a dedicated MySQL database + user, never a reused one.
- `wp-config.php` — generated fresh for this site, never copied from another.
- WordPress authentication keys/salts — fresh, never reused (see §5).
- `wp-content/uploads` — this site's own directory; never symlinked to another site's.
- **Redis logical database index** (`TUBE_CACHE_REDIS_DB` / `TUBE_CORE_REDIS_DB`) if this site shares a Redis server with another site on the same VPS — see §7. This is the single most important thing this document exists to get right: two sites sharing one Redis server at the same default database index will silently pollute each other's cached "Trending"/"Most Viewed"/"Recently Added" listings and buffered view counts. There is no way to detect this from either site's own database — it manifests as one site's content appearing on the other's homepage.
- Cron jobs — installed with this site's own `--path`, never appended to or copied from another site's crontab entries.
- Cloudflare Stream credentials, if this site has its own Stream videos not shared with another site (§8).
- Site URL / domain (`siteurl`/`home` options, set via the standard WordPress install flow, never hardcoded into a template).

## What may be intentionally shared across sites (if that's the real architecture)

- **The R2 bucket / signing secret**, if multiple sites are meant to serve videos from one shared media library (this project's own case: `phimtoico.org` and `dongtoico.org` intentionally share one R2 bucket and Worker). If sites should have *separate* media libraries instead, provision a separate R2 bucket + Worker + signing secret per site — do not share `TUBE_CORE_R2_SIGNING_SECRET` in that case.
- The Cloudflare account/Worker itself, when R2 is shared (never redeploy or reconfigure the Worker just to onboard a new site sharing the same bucket — see `infrastructure/cloudflare/r2-media-worker/README.md`).
- The physical Redis *server* (host/port) — only the logical database index within it needs to be distinct per site.
- The physical MySQL *server* — only the database/user needs to be distinct per site.

## 1. Create the new webroot

```sh
mkdir -p /www/wwwroot/<new-domain>
chown www:www /www/wwwroot/<new-domain>
```

## 2. Install a clean WordPress core

Match the version already running on other sites on this VPS for consistency (check with `wp core version --path=/www/wwwroot/<existing-site>`):

```sh
wp core download --path=/www/wwwroot/<new-domain> --version=<x.y> --allow-root
```

Remove WordPress's own bundled default plugins/themes — this project's `tube-*` plugins and `tube-theme` are the only ones that belong here:

```sh
rm -rf /www/wwwroot/<new-domain>/wp-content/plugins/akismet
rm -rf /www/wwwroot/<new-domain>/wp-content/plugins/hello.php
rm -rf /www/wwwroot/<new-domain>/wp-content/themes/twentytwenty*
```

## 3. Deploy this release's code

From a clean checkout of the tagged release (never the live directory of another site — see `docs/DEPLOYMENT.md` §3 for the full per-plugin `composer install --no-dev` loop, which applies identically here):

```sh
git clone --branch <release-tag> <this-repo-url> /tmp/release-build
for plugin_dir in /tmp/release-build/wp-content/plugins/*/; do
  if [ -f "${plugin_dir}composer.json" ]; then
    (cd "${plugin_dir}" && composer install --no-dev --optimize-autoloader --no-interaction)
  fi
done
for plugin in tube-admin tube-ads tube-cache tube-comments tube-core tube-members tube-player tube-search tube-seo; do
  rsync -a "/tmp/release-build/wp-content/plugins/$plugin/" "/www/wwwroot/<new-domain>/wp-content/plugins/$plugin/"
done
rsync -a /tmp/release-build/wp-content/themes/tube-theme/ /www/wwwroot/<new-domain>/wp-content/themes/tube-theme/
chown -R www:www /www/wwwroot/<new-domain>/wp-content/plugins /www/wwwroot/<new-domain>/wp-content/themes
```

## 4. Create an independent database

Via your panel's UI (recommended — avoids ever touching MySQL root credentials from a script) or equivalent SQL run by a human with existing access:

```sql
CREATE DATABASE `<new_db_name>` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '<new_db_user>'@'localhost' IDENTIFIED BY '<strong random password>';
GRANT ALL PRIVILEGES ON `<new_db_name>`.* TO '<new_db_user>'@'localhost';
FLUSH PRIVILEGES;
```

Never reuse an existing site's database name/user, and never grant privileges beyond that one database.

## 5. Create an independent `wp-config.php`

Use `wp config create` (interactively or with `--prompt=dbpass` so the password is never a shell argument) against the new database, then generate fresh salts from the official API — **never copy another site's salts**:

```sh
curl -s https://api.wordpress.org/secret-key/1.1/salt/
```

Append the project-specific constants below, using this site's *own* values — see the "Constant reference" section for what each one does and whether it's required.

If the panel already generated `wp-config.php` for you (e.g. aaPanel's one-click WordPress installer, which pre-populates `DB_*`, salts, *and* `define('WP_DEBUG', false)`), check for an existing `WP_DEBUG` define before appending your own — a real clone hit this: the standard-hardening block below already includes `WP_DEBUG`, and appending a second `define('WP_DEBUG', ...)` produces a "Constant WP_DEBUG already defined" PHP warning on every single request (harmless, but noisy in the error log and worth avoiding). Drop whichever of the two is redundant.

## 6. Configure the site URL

Set via the standard WordPress install (`wp core install --url=https://<new-domain> --title="..." --admin_user=... --admin_email=...`), which writes `siteurl`/`home` into `wp_options` — do not hardcode `WP_HOME`/`WP_SITEURL` as wp-config constants unless this environment specifically needs to derive them from a dynamic `Host` header (see this repo's `docker-compose.yml` for that local-dev-only pattern; production sites should not need it).

## 7. Configure Redis isolation (if sharing a Redis server with another site)

Check what logical database indices are already in use by other sites on this VPS (ask, or check their `wp-config.php` for `TUBE_CACHE_REDIS_DB`/`TUBE_CORE_REDIS_DB` — absence means index 0), then pick an unused one (0–15, Redis's default range) for this new site:

```php
define('TUBE_CACHE_REDIS_DB', <unused index>);
define('TUBE_CORE_REDIS_DB', <same unused index>);
```

Both constants should use the *same* index for one site (`tube-members`/`tube-comments` reuse `TUBE_CORE_REDIS_DB` automatically — see their own `Plugin.php`). If this site has its own dedicated Redis server instead of sharing one, set `TUBE_CACHE_REDIS_HOST`/`TUBE_CORE_REDIS_HOST` to that server and the `_DB` constants can be left at the default (`0`).

## 8. Configure R2 / Stream runtime values

**R2** (only if this site serves R2/direct-MP4 videos): set `TUBE_CORE_R2_MEDIA_BASE_URL` and `TUBE_CORE_R2_SIGNING_SECRET`. If sharing an existing R2 bucket/Worker with another site (the common case — see the "may be shared" section above), use the *exact same* values as that site; do not generate a new secret or redeploy the Worker. If this site needs its own separate media library, provision a new bucket + Worker (`infrastructure/cloudflare/r2-media-worker/README.md`) and generate a fresh secret for it.

**Cloudflare Stream** (only if this site serves Stream videos): set `TUBE_CORE_CLOUDFLARE_STREAM_ACCOUNT_ID`, `TUBE_CORE_CLOUDFLARE_STREAM_API_TOKEN`, `TUBE_PLAYER_CLOUDFLARE_STREAM_CUSTOMER_CODE`. These may be reused from another site's config *only* if both sites genuinely share the same Cloudflare Stream account and its videos are meant to be visible to both — otherwise provision a separate Stream-scoped API token. The webhook secret (`TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET`) is only meaningful for the *one* site whose URL is actually registered as the Stream account's webhook endpoint — a second site sharing the same Stream account cannot also receive that webhook (Cloudflare registers one URL per account); rely on `wp tube-core stream:resync` instead for that site.

Every constant is optional in the sense that the relevant source is simply unavailable/unconfigured if omitted (fails closed — see each constant's own docblock in code) — but a site publishing R2 or Stream videos needs its corresponding block configured or every such video will be unusable.

**R2 duration is automatic — no extra configuration needed.** As of the fix that shipped in `R2Mp4DurationProber`, publishing/saving an R2 video with the admin's duration field left blank automatically probes the real duration via a couple of small, bounded HTTP Range reads (never a full download) and stores it in the same canonical `duration_seconds` column Stream already uses — the existing video-card duration badge picks it up with no further action. This *requires the R2 origin to honor HTTP Range requests* (`Accept-Ranges: bytes`, real `206 Partial Content` responses) — already a hard requirement for the player itself (seeking depends on it), so any origin/Worker already serving this project's R2 videos already satisfies it; nothing extra to configure per site. The admin's manual duration field still exists purely as a fallback for the rare file the probe can't parse.

## Configure the site's visual brand (optional)

Every site shares one theme codebase (`tube-theme`) but can have its own distinct visual identity via `TUBE_THEME_SITE_BRAND` (see `docs/wp-config-constants.example.php`). Leave the constant undefined entirely for the shared default brand (this is the common case for a brand-new site with no bespoke design yet) — that's a complete, safe, zero-effort choice with no downsides.

If this site needs its own distinct identity:

1. Pick a new, site-specific brand value never used by another site (e.g. the site's own short name) — check every other site's `wp-config.php` first to be sure it isn't already taken; two sites sharing one brand value is treated as a real bug, not a supported configuration.
2. Add `assets/css/site-{brand}.css` to the theme (scope every selector under `body.site-brand-{brand}` — this is what guarantees zero visual leakage to any other site sharing this codebase).
3. Set `define('TUBE_THEME_SITE_BRAND', '{brand}');` in this site's own `wp-config.php` only.
4. Verify: this site's homepage `<body>` carries `site-brand-{brand}`, and every *other* site's `<body>` class and rendered CSS are completely unchanged (diff a full-page screenshot or the raw HTML against that other site's pre-change state).

No PHP template changes are required for a new brand unless its design genuinely needs structurally different markup, not just different styling — see `template-parts/hero.php` for the established pattern (a brand-gated branch per brand, sharing the same file, never a forked copy of it).

### Constant reference (names only — see code for exact semantics; never commit real values)

| Constant | Purpose | Shared or per-site? |
|---|---|---|
| `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST` | Database connection | Per-site (always) |
| `AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_KEY`, `AUTH_SALT`, `SECURE_AUTH_SALT`, `LOGGED_IN_SALT`, `NONCE_SALT` | WordPress auth cookies | Per-site (always) |
| `WP_CACHE_KEY_SALT` | WordPress core cache-key namespacing | Per-site (always) |
| `DISABLE_WP_CRON` | Disables WP-Cron pseudo-cron; this project uses real Linux cron instead | Same value everywhere (`true`) |
| `WP_DEBUG`, `WP_DEBUG_DISPLAY` | Debug mode | Same value everywhere in production (`false`) |
| `TUBE_CACHE_REDIS_HOST`, `TUBE_CACHE_REDIS_PORT` | tube-cache's Redis connection | Shared if using one Redis server |
| `TUBE_CACHE_REDIS_DB` | tube-cache's Redis logical database index | **Per-site**, see §7 |
| `TUBE_CORE_REDIS_HOST`, `TUBE_CORE_REDIS_PORT` | tube-core's Redis connection (view-count buffering); also reused by tube-members/tube-comments rate limiting | Shared if using one Redis server |
| `TUBE_CORE_REDIS_DB` | tube-core's Redis logical database index (also reused by tube-members/tube-comments) | **Per-site**, see §7 |
| `TUBE_CORE_R2_MEDIA_BASE_URL` | The R2 custom domain videos are served from | Shared if sharing an R2 bucket, else per-site |
| `TUBE_CORE_R2_SIGNING_SECRET` | HMAC secret the R2 Worker verifies signed playback URLs against | Shared if sharing an R2 bucket/Worker, else per-site |
| `TUBE_CORE_CLOUDFLARE_STREAM_ACCOUNT_ID`, `TUBE_CORE_CLOUDFLARE_STREAM_API_TOKEN` | Stream:Read API access for resync/manual UID entry | Shared if sharing a Stream account, else per-site |
| `TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET` | Verifies incoming Stream webhook requests | Only meaningful for the one site actually registered as the webhook endpoint |
| `TUBE_PLAYER_CLOUDFLARE_STREAM_CUSTOMER_CODE` | Builds the public Stream playback/iframe URL | Shared if sharing a Stream account, else per-site |
| `TUBE_PLAYER_CLOUDFLARE_STREAM_SIGNING_KEY_ID`, `TUBE_PLAYER_CLOUDFLARE_STREAM_SIGNING_KEY_PEM_BASE64` | Optional signed Stream playback URLs (unsigned mode if omitted) | Shared if sharing a Stream account, else per-site |
| `TUBE_PLAYER_SIGNED_URL_TTL_SECONDS` | TTL for signed Stream URLs (default 3600) | Same value everywhere unless intentionally different |
| `TUBE_PLAYER_CLOUDFLARE_IMAGES_ACCOUNT_HASH` | Cloudflare Images account hash for actor/studio photos | Shared if sharing a Cloudflare Images account, else per-site |
| `TUBE_THEME_SITE_BRAND` | Selects this site's own `assets/css/site-{brand}.css` visual identity | **Per-site** (omit for the shared default brand; a non-default value must never be reused by two sites) |

A ready-to-copy template with every one of these as an empty placeholder is at `docs/wp-config-constants.example.php` — never fill it with real values and commit it; copy it out of the repo first.

## 9. Run migrations

```sh
wp tube migrate status --path=/www/wwwroot/<new-domain>
wp tube migrate up --path=/www/wwwroot/<new-domain>
```

`tube-core`'s own migrations run automatically on plugin activation (step 10); this command is what applies `tube-comments`/`tube-search`'s migrations, which do not auto-run. Always run `status` first on a fresh site to confirm the full expected set (all `tube-core` 001–014+, `tube-comments` 001–005+, `tube-search` 001–002+, or whatever the current release's actual migration count is) ends up `applied`.

## 10. Activate theme/plugins

```sh
wp theme activate tube-theme --path=/www/wwwroot/<new-domain>
wp plugin activate tube-core tube-cache tube-player tube-search tube-seo tube-admin tube-members tube-ads tube-comments --path=/www/wwwroot/<new-domain>
```

## 11. Install site-specific cron jobs

**Append** to the existing crontab (never overwrite it — other sites' entries must survive) using this site's own `--path` and dedicated log filenames so multiple sites' cron output never interleaves in the same file:

```
* * * * *     wp --path=/www/wwwroot/<new-domain> --allow-root tube-core views:flush >> /var/log/tube-cron/<new-domain>-views-flush.log 2>&1
*/5 * * * *   wp --path=/www/wwwroot/<new-domain> --allow-root tube-core stats:rollup >> /var/log/tube-cron/<new-domain>-stats-rollup.log 2>&1
0 3 * * *     wp --path=/www/wwwroot/<new-domain> --allow-root tube-core stats:rollup --full >> /var/log/tube-cron/<new-domain>-stats-rollup-full.log 2>&1
* * * * *     wp --path=/www/wwwroot/<new-domain> --allow-root tube-core import:process >> /var/log/tube-cron/<new-domain>-import-process.log 2>&1
0 2 * * *     wp --path=/www/wwwroot/<new-domain> --allow-root tube-core views:partition-maintenance >> /var/log/tube-cron/<new-domain>-partition-maintenance.log 2>&1
0 4 * * *     wp --path=/www/wwwroot/<new-domain> --allow-root tube-core watch-history:purge >> /var/log/tube-cron/<new-domain>-watch-history-purge.log 2>&1
0 1 * * *     wp --path=/www/wwwroot/<new-domain> --allow-root tube-search index:rebuild >> /var/log/tube-cron/<new-domain>-index-rebuild.log 2>&1
0 * * * *     wp --path=/www/wwwroot/<new-domain> --allow-root tube-seo sitemap:generate >> /var/log/tube-cron/<new-domain>-sitemap-generate.log 2>&1
```

`crontab -l > /tmp/current.txt && cat >> /tmp/current.txt <<'EOF' ... EOF && crontab /tmp/current.txt` is the safe non-interactive way to append.

## 12. Verify nginx/SSL

If cloning an existing, already-working vhost template on the same panel, the nginx config (`server_name`, PHP-FPM routing, pretty-permalink rewrite rules, SSL cert) is usually already correctly generated per-domain by the panel and needs no manual edits — verify rather than assume:

- `nginx -t` passes.
- `curl -I https://<new-domain>/` returns a valid HTTPS response with a certificate for the correct domain.
- HTTP redirects to HTTPS.
- A pretty-permalink URL (not just `/`) returns 200, confirming the rewrite rule is in place. Do not assume this from `/` returning 200 alone — a real clone hit an aaPanel-created site whose `vhost/rewrite/<domain>.conf` include (referenced by the main vhost's `#REWRITE-START` block) was silently **empty**, so `/` (served directly by `index.php`) worked fine while every other URL 404'd. If it's empty, populate it with the standard `location / { try_files $uri $uri/ /index.php?$args; }` block (PHP routing is already handled by the vhost's own `include enable-php-83.conf`, so no second `location ~ \.php$` block is needed) and reload nginx.

## 13. Run smoke tests

- Homepage 200.
- `wp-admin` reachable, login works.
- `Videos → Add New` renders with zero PHP fatals, and the Video Source selector shows both Cloudflare Stream and R2/MP4 options.
- Create one real video through the actual admin form (not a direct DB write) for whichever source(s) this site uses, and confirm it reaches `Ready`/plays on the first save — see `docs/DEPLOYMENT.md` §4 for the general smoke-test checklist, which applies here too.
- For an R2 video specifically: leave the admin's duration field blank and confirm a real `duration_seconds` is stored automatically (no manual entry, no direct DB edit) and the video-card duration badge renders it on the homepage. Also confirm signed playback works, a `Range` request against the playback URL returns `206`, and seeking in the player works.
- `wp tube migrate status` shows everything `applied`.
- Search page returns 200.
- Trigger each cron command manually once (`wp tube-core stats:rollup --path=...`, etc.) and confirm no fatal.
- If sharing Redis with another site: confirm that site's homepage still shows *only its own* content after this new site's first real traffic (the concrete regression test for §7 actually being right).
- If this site was given its own `TUBE_THEME_SITE_BRAND`: confirm its homepage shows that brand's `site-brand-{brand}` body class and styling, and re-check every other site sharing this codebase still shows its own unchanged brand.
