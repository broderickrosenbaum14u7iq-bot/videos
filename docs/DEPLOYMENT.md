# Production Deployment Checklist

Written for the 1.0.0 release, against the confirmed production target: a single VPS (`root@139.99.96.155:/www/wwwroot/phimtoico.org`), 3,000–10,000 videos, a few million pageviews/month, Redis, MySQL, Cloudflare CDN — no read replicas, no partitioning, no distributed infrastructure (`PHASE-11.md`). Production is **not** the Docker Compose staging stack (`docker-compose.yml`) — it is a native LEMP-style server. Every principle here follows `ARCHITECTURE.md` §18's Operations Handbook; this document makes it concrete and copy-pasteable for that specific server shape.

**Never run these steps against production from an AI assistant session.** This document is for the human operator (or a reviewed, human-triggered deploy script) to execute. Per `DEVELOPMENT_RULES.md` §3, all implementation and verification happens in the local Docker staging environment; production is only ever touched through this documented, reviewed procedure.

---

## 1. Pre-deployment gate (must all be true before touching production)

- [ ] `git status` on `main` is clean; the commit being deployed is tagged (e.g. `v1.0.0`).
- [ ] `vendor/bin/phpcs` exits `0` on the tagged commit.
- [ ] `vendor/bin/phpstan analyse --memory-limit=1G` reports `[OK] No errors` on the tagged commit.
- [ ] Every plugin's unit suite passes (`cd wp-content/plugins/<plugin> && vendor/bin/phpunit`).
- [ ] Every plugin's integration suite passes inside the `wpcli` container (`vendor/bin/phpunit -c phpunit-integration.xml.dist`).
- [ ] `ops/benchmark/run.sh` has been run against staging on this commit and compared against the latest `BENCHMARKS.md` section — no unexplained regression.
- [ ] `wp tube migrate status` on staging shows every migration this release introduces as pending (not yet applied) — confirms the migration set about to run on production is the one actually reviewed.
- [ ] **Clean-checkout boot test**: in a scratch directory (not the long-lived staging checkout, which accumulates `vendor/` directories built up over the project's history and can mask a packaging gap), `git clone` the tagged commit fresh, run the §3 step 2 per-plugin `composer install` loop, point a throwaway WordPress install at it, and confirm all 6 plugins activate with zero fatals. A staging environment that has never had its `vendor/` directories deleted is not a substitute for this — it will boot successfully even if the release's actual deploy procedure is broken, which is exactly how the v1.0.0 tag shipped without this step documented.
- [ ] A fresh, verified backup of the production database exists (§ Backup/Restore, `docs/BACKUP_RESTORE.md`) — taken **before** this deploy, not relied upon from an older one.
- [ ] Release notes are in `RELEASE.md` / `CHANGELOG.md` and describe exactly what's shipping.

## 2. First production deployment only (this project has never deployed before 1.0.0)

Additional one-time setup, before the standard deploy sequence below applies for the first time:

- [ ] Provision the server: PHP 8.3-FPM, MySQL/MariaDB (matching the `mariadb:11.4` version tested in staging), Redis 7, nginx, WP-CLI, Composer, Linux `cron` — matching `ARCHITECTURE_FREEZE.md`'s frozen "PHP 8.3 only" and staging's tested stack.
- [ ] Install WordPress at `/www/wwwroot/phimtoico.org`, `Requires at least: 6.5` (every plugin header).
- [ ] Create the production database and a dedicated MySQL user scoped to it (never the MySQL root user for the application).
- [ ] Set `DISABLE_WP_CRON` to `true` in `wp-config.php` — WP-Cron is never used in this architecture (`ARCHITECTURE_FREEZE.md` #8); every scheduled job runs via the Linux crontab in §5 below.
- [ ] Set `WP_DEBUG` to `false` and `WP_DEBUG_DISPLAY` to `false` in production `wp-config.php`. If error logging is wanted, use `WP_DEBUG_LOG` (writes to a file, never displays to a visitor) — never enable display-to-browser error output on production, which none of this project's staging config represents (staging's `docker-compose.yml` sets no `WP_DEBUG` at all, since staging errors are read from `docker compose logs`, not a browser).
- [ ] Define every Cloudflare secret (`TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET`, `TUBE_ADMIN_CLOUDFLARE_IMAGES_API_TOKEN`, and the Redis host/port constants — see `docker-compose.yml`'s `WORDPRESS_CONFIG_EXTRA` block for the full, current list of `define()` calls to replicate) directly in production `wp-config.php` from real Cloudflare account credentials — **never** the staging placeholder values, and never committed to git (this repo's `.gitignore` already excludes `.env*`; the same discipline applies to `wp-config.php` secrets on the server itself — file permissions, not git, are what protects them there).
- [ ] Point Cloudflare DNS/CDN at the server; confirm Cloudflare Stream and Cloudflare Images are provisioned on the real account referenced by the secrets above.
- [ ] Configure the Cloudflare Stream webhook to point at `https://<production-domain>/wp-json/tube/v1/webhooks/cloudflare-stream`, using the same secret defined above (`WebhookSignatureVerifier` — `docs/DEPLOYMENT.md` §4 covers verifying this works before declaring the deploy live).
- [ ] Install the Linux crontab from §5 below, adapted from `ops/cron/staging.cron`'s cadences (do not change the cadences without re-reading `ARCHITECTURE.md` §7 — they are a frozen-adjacent operational decision, not arbitrary).
- [ ] Set up log rotation for `/var/log/tube-cron/*.log` (staging's cron container has no rotation because it's ephemeral; production's is not).

## 3. Standard deploy sequence (every release, including 1.0.0)

Per `ARCHITECTURE.md` §18.1, using an atomic symlink swap so a bad deploy reverts instantly:

1. `git fetch --tags && git checkout v1.0.1` (or the release tag being deployed) into a **new** release directory, e.g. `/www/wwwroot/phimtoico.org/releases/v1.0.1/`. Never deploy by editing the live directory in place.
2. **Run `composer install --no-dev --optimize-autoloader` separately inside every plugin directory that has its own `composer.json`** — this project has no shared runtime autoloader (`ARCHITECTURE.md` §4: each plugin must remain independently `composer install`-able; the repo-root `composer.json` is dev tooling only — PHPCS/PHPStan — and has no `autoload` section at all, so running `composer install` only at the release root silently leaves every plugin's `vendor/autoload.php` missing and every plugin fatals on boot with `Class "Tube_X\Plugin" not found`. This is not optional per plugin — even the four plugins with zero third-party packages (`tube-player`, `tube-search`, `tube-seo`, `tube-admin`) still need their own generated `vendor/autoload.php`, since that file is what registers their `Tube_X\` PSR-4 namespace; nothing else in the boot path does):
   ```sh
   for plugin in tube-core tube-cache tube-player tube-search tube-seo tube-admin; do
     (cd "wp-content/plugins/${plugin}" && composer install --no-dev --optimize-autoloader --no-interaction)
   done
   ```
   `--no-dev` excludes every plugin's `phpunit`/dev-only dependency (confirmed in the final security review: every plugin's `composer.json` keeps `phpunit/phpunit` under `require-dev` only). Every plugin's `composer.lock` is git-tracked, so this is a reproducible `install`, never an `update` — the exact locked versions reviewed in staging are what ships to production.
3. Copy/symlink the production `wp-config.php` and any persistent `wp-content/uploads` directory into the new release directory (these are not part of the git-tracked release; see `docs/BACKUP_RESTORE.md` for what's backed up vs. what's git-tracked).
4. `wp tube migrate status --path=<new-release-path>` — review exactly what's about to run. If any migration touches a large/populated table, confirm it was already dry-run against a production-scale staging copy per `ARCHITECTURE.md` §18.4 before proceeding.
5. `wp tube migrate up --path=<new-release-path>`.
6. Flip the `current` symlink to point at the new release directory (the actual atomic cutover — nginx/PHP-FPM serve through this symlink, so this single `ln -sfn` is the entire moment traffic starts hitting new code).
7. `wp cache flush --path=<new-release-path>` (WordPress object cache only — this project's own Redis cache, `tube-cache`, is purged per-event by its own `CachePurgeSubscriber`, not wholesale on deploy; a wholesale Redis flush is not part of a normal deploy).
8. Smoke test immediately (§4 below).
9. Watch error logs for the following 15 minutes (`ARCHITECTURE.md` §18.1) — see `docs/MONITORING.md` for exactly what to watch.
10. Keep the last 5 release directories on disk for instant rollback (`docs/ROLLBACK.md`); prune older ones only after confirming the current release is stable.

Most deploys are zero-downtime (step 6 is instant). A maintenance window is only needed for a migration that locks a large table or isn't backward-compatible with the code being replaced — migrations in this project default to expand/contract specifically to avoid needing one for the common case.

## 4. Smoke test (run after every deploy, before declaring it complete)

- [ ] Homepage loads: `curl -o /dev/null -w '%{http_code}' https://<domain>/` → `200`.
- [ ] A real video page loads: `curl -o /dev/null -w '%{http_code}' https://<domain>/watch/<a-real-slug>/` → `200`.
- [ ] `POST /wp-json/tube/v1/videos/<id>/watch-history` (with a real published video ID and a `progress_seconds` body param — the route only accepts `POST`, per `WatchHistoryController`) returns `200`, confirming the `tube/v1` REST namespace is registered and reachable.
- [ ] `wp tube migrate status --path=<new-release-path>` shows every migration `applied`.
- [ ] `wp --path=<new-release-path> plugin list` shows all 6 plugins `active` at the deployed version.
- [ ] Trigger a real (or Cloudflare's dashboard "send test webhook") Cloudflare Stream webhook and confirm it's accepted (`WebhookSignatureVerifier` validates it) — do this once after the very first production deploy and after any change touching webhook handling, not necessarily every release.
- [ ] Check `wp-admin` is reachable and redirects unauthenticated requests to login (`302`), and that a real admin login can reach `admin.php?page=tube-admin`.

## 5. Production crontab

Install as the application user's crontab (or `/etc/cron.d/tube-site` if using system cron), adapted from `ops/cron/staging.cron`'s real (non-placeholder) commands — every command in staging's crontab is already real as of Phase 11, so this is a direct path/log-location adaptation, not new content to design:

```
# Tube Site — production crontab. Cadences match ARCHITECTURE.md §7.
# Adapted from ops/cron/staging.cron for a native (non-Docker) server.

* * * * *     wp --path=/www/wwwroot/phimtoico.org/current --allow-root tube-core views:flush >> /var/log/tube-cron/views-flush.log 2>&1
*/5 * * * *   wp --path=/www/wwwroot/phimtoico.org/current --allow-root tube-core stats:rollup >> /var/log/tube-cron/stats-rollup.log 2>&1
0 3 * * *     wp --path=/www/wwwroot/phimtoico.org/current --allow-root tube-core stats:rollup --full >> /var/log/tube-cron/stats-rollup-full.log 2>&1
* * * * *     wp --path=/www/wwwroot/phimtoico.org/current --allow-root tube-core import:process >> /var/log/tube-cron/import-process.log 2>&1
0 2 * * *     wp --path=/www/wwwroot/phimtoico.org/current --allow-root tube-core views:partition-maintenance >> /var/log/tube-cron/partition-maintenance.log 2>&1
0 4 * * *     wp --path=/www/wwwroot/phimtoico.org/current --allow-root tube-core watch-history:purge >> /var/log/tube-cron/watch-history-purge.log 2>&1
0 1 * * *     wp --path=/www/wwwroot/phimtoico.org/current --allow-root tube-search index:rebuild >> /var/log/tube-cron/index-rebuild.log 2>&1
0 * * * *     wp --path=/www/wwwroot/phimtoico.org/current --allow-root tube-seo sitemap:generate >> /var/log/tube-cron/sitemap-generate.log 2>&1
*/5 * * * *   wp --path=/www/wwwroot/phimtoico.org/current --allow-root cron event run --due-now >> /var/log/tube-cron/wp-cron-safety-net.log 2>&1
```

`--path` points at the `current` symlink (§3 step 6), not a specific release directory, so cron jobs always run against whatever is currently live without needing a crontab edit on every deploy.

## 6. Post-launch (first 48 hours)

- [ ] Watch the dashboards/logs in `docs/MONITORING.md` more frequently than the steady-state cadence recommends.
- [ ] Confirm the very first `stats:rollup --full` (nightly, 03:00) and `index:rebuild` (nightly, 01:00) and `sitemap:generate` (hourly) runs all succeed with a real production data volume, not just staging's near-empty fixture data.
- [ ] Confirm Redis memory usage and MySQL slow-query log stay within expectations under real traffic (`ARCHITECTURE_FREEZE.md`'s Performance Assumptions are staging-verified, not production-verified, until this point).
