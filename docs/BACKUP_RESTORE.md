# Backup and Restore

Concrete procedure for `ARCHITECTURE.md` §18.2's backup policy, scaled to the confirmed production target (single VPS, 3,000–10,000 videos — not the original 500,000-video design ceiling §18.2 also covers). At this target's real size, a logical `mysqldump` restore is fast enough to be the primary method; §18.2's own physical/snapshot recommendation is explicitly conditioned on "once the database is large (500,000+ videos)," which this target isn't.

## 1. What is backed up

- **The database.** Every `wp_*` and `wp_tube_*` table. This is the only thing that needs backing up on a routine cadence — everything else below is either in git or on Cloudflare.
- **`wp-config.php`** (contains production secrets — back it up, but store it somewhere at least as protected as the server itself, never alongside a database dump in a less-protected location).
- **`wp-content/uploads`** — WordPress core's own upload directory. This project stores no video/image *bytes* here by design (`ARCHITECTURE_FREEZE.md` #5: "No video/image bytes are ever stored on the WordPress server"), so this directory should stay small; back it up anyway for whatever core/theme assets do land there (it is not git-tracked).

## 2. What is NOT backed up (and why that's correct, not an oversight)

- **Application code** — git (`origin/main`, tagged releases) is the source of truth. A code "backup" is redeployment from a tag, not a file copy.
- **Video/image bytes** — never stored on the WordPress server at all. Cloudflare Stream/Images hold the actual media; the database only stores their IDs (`ARCHITECTURE_FREEZE.md` #5). Cloudflare's own durability covers this, not this project's backup process. See `docs/DISASTER_RECOVERY.md`-equivalent scenarios in `ARCHITECTURE.md` §18.6 for what "Cloudflare account issue" means for this design.
- **Redis** — buffered view counts (`RedisViewCounter`) and the object cache. Bounded, acceptable loss: at most the unflushed portion of the last `views:flush` interval (every minute, per §5 of `ops/cron/staging.cron`'s cadence) if Redis is lost between backups. Not a restore target.
- **`wp_tube_search_index`** — technically part of the database dump (so it *is* captured), but never a critical restore dependency: `wp tube-search index:rebuild` regenerates it completely from `wp_posts` + `wp_tube_video_metadata` + taxonomies, so a stale or lost search index is a same-day, self-healing problem, not a disaster-recovery one.

## 3. Backup procedure

Daily, via cron (not part of the application's own crontab in `docs/DEPLOYMENT.md` §5 — this is infrastructure-level, typically the hosting control panel's own scheduled backup or a separate ops cron entry):

```bash
# Logical dump — sufficient at this target's real scale (3,000-10,000 videos).
mysqldump --single-transaction --quick \
  -u <backup_user> -p<password> <production_db_name> \
  | gzip > /backups/tube-site/db-$(date +%Y%m%d-%H%M%S).sql.gz
```

- `--single-transaction` avoids locking InnoDB tables for the duration of the dump (every `wp_tube_*` table in this project is InnoDB by default via `dbDelta()`).
- Store backups **off the origin server** (object storage, or a separate host) — this directly closes the gap found in this project's original site audit, where a backup plugin was installed but its backup directory was empty locally and nothing shipped it elsewhere.
- Retention: 14 daily + 8 weekly + 6 monthly is a reasonable starting policy for this target's scale; confirm the actual business requirement before launch rather than treating this as decided (`ARCHITECTURE.md` §18.2 leaves this explicitly open).
- Back up `wp-config.php` and `wp-content/uploads` on the same cadence, to the same off-server destination, separately from the database dump (different restore path, different sensitivity for `wp-config.php`).

## 4. Restore procedure

**To a scratch/verification environment (do this monthly — an unverified backup is not a backup):**

```bash
mysql -u root -p -e "CREATE DATABASE tube_restore_test;"
gunzip -c /backups/tube-site/db-<date>.sql.gz | mysql -u root -p tube_restore_test
```

Then point a scratch WordPress install's `wp-config.php` at `tube_restore_test` and confirm:
- `wp tube migrate status` shows the expected set of applied migrations for that backup's date.
- A known real video's page renders correctly.
- `wp-admin` login works and the Statistics/Import dashboards show real data.

Drop the scratch database afterward.

**To production, for a real disaster recovery event:**

1. Stop PHP-FPM / take the site into maintenance mode — do not restore into a database still receiving writes.
2. `gunzip -c <chosen-backup>.sql.gz | mysql -u <user> -p <production_db_name>` — restores into the **existing** production database (drop-and-recreate it first if it's in an unknown/corrupted state, per whatever the actual failure was).
3. Restore `wp-config.php` and `wp-content/uploads` from the same backup set if either was also lost (a database-only corruption doesn't need this step).
4. `wp tube migrate status` — confirm the restored database's migration state matches what the **currently deployed code** (the `current` symlink target, `docs/DEPLOYMENT.md` §3) expects. If the backup predates a migration the running code depends on, `wp tube migrate up` before resuming traffic — never leave code running against a schema it doesn't expect.
5. Bring PHP-FPM back up; smoke test (`docs/DEPLOYMENT.md` §4).
6. Accept the bounded data loss this restore represents: any view/import/watch-history writes between the backup's timestamp and the restore are gone (Redis-buffered view counts already carried at most one flush interval of risk on top of that, per §2 above) — this is the accepted tradeoff `ARCHITECTURE_FREEZE.md`'s Known Tradeoffs section already documents for the DB-table import queue and Redis, not a new decision being made mid-incident.

See `ARCHITECTURE.md` §18.6 for the broader disaster-recovery scenario catalog (full server loss, Cloudflare account issue, Redis loss) this restore procedure is one piece of.
