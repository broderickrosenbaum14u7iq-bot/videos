# Phase 1 Audit

Status: **Complete. Clean.** Performed before starting Phase 2, per the review requested. This document records what was checked, what was found, and what was changed — including the things that were reviewed and found correct, not only the fixes, so the audit trail is honest about scope.

---

## 1. Database normalization

Reviewed all six tables (`wp_tube_schema_versions`, `wp_tube_video_metadata`, `wp_tube_actors`, `wp_tube_video_actors`, `wp_tube_studios`, `wp_tube_video_studios`) against 3NF.

**Finding: clean.** Every non-key column in every table depends on the whole of that table's primary key and nothing else. `wp_tube_video_metadata` is a correct 1:1 extension of the `video` CPT. The two junction tables (`wp_tube_video_actors`, `wp_tube_video_studios`) carry no redundant columns beyond the two foreign-key-shaped identifiers, which is the correct shape for a many-to-many relationship.

**Deliberate denormalization, not a defect**: `video_count` on `wp_tube_actors`/`wp_tube_studios` is a cached aggregate of the corresponding junction table, maintained by application code rather than computed live — this was a documented design choice from Phase 1 (avoids a `COUNT()` aggregation on every actor/studio page load) and is correctly noted in the migration comments as "not recalculated automatically the way WordPress does for taxonomy terms." No population code exists yet because nothing writes to these tables yet (that's Phase 7+); the column is correctly in place ahead of that.

## 2. Indexes

Cross-checked every index against (a) the queries `MigrationRunner`/`SchemaVersionStore` actually run today, and (b) the access patterns ARCHITECTURE.md specifies for later phases. Verified live against the running database with `SHOW INDEX`, not just against the migration source.

**Finding, fixed**: `wp_tube_actors` and `wp_tube_studios` had no index on `name` — only on `slug`. ARCHITECTURE.md §10 explicitly requires "AJAX-searchable term pickers" for actor/studio at scale, which means a name-prefix lookup neither table could serve efficiently. Added via a new **Migration004AddActorStudioNameIndexes** (§4 below) rather than editing Migration002/003, since those were already applied — migrations are not rewritten after the fact.

Everything else checked out already correct:
- `wp_tube_schema_versions`'s `(plugin_slug, version)` composite key serves both the `WHERE plugin_slug = %s` filter and the `ORDER BY version ASC` from the same index, no filesort.
- `wp_tube_video_metadata`'s `cf_stream_uid_idx` (unique) and `cf_status_idx` directly serve the two access patterns already named in its own migration comment: resolving a Cloudflare webhook by UID, and admin queries for "still processing/error" videos.
- Both junction tables are indexed in **both** directions (`(video_id, actor_id)` PK + `(actor_id, video_id)` secondary, and the studio equivalent) — this is what makes "all actors for this video" and "all videos for this actor" both O(log n) lookups, and the secondary index is a covering index for the latter (no row lookup needed beyond the index itself).

## 3. Migration rollback

Re-verified beyond Phase 1's original happy-path check:
- Live-tested calling `migrate_down --to=001` a second time when already at 001 — correctly rolled back whatever was actually applied at the time (002, 003) and is a true no-op when nothing above the target is applied, matching the existing unit test `test_migrate_down_only_rolls_back_migrations_that_were_actually_applied`.
- Live-tested rolling back **only** migration 004 (`--to=003`) — `name_idx` was dropped from both tables while `slug_idx`/`parent_id_idx`/all data remained untouched, confirmed via `SHOW INDEX`.
- Traced `migrate_up`'s interaction with a partially-applied plugin when `--to` targets an earlier version than what's already applied: it correctly does nothing beyond the target and never un-applies later versions (`migrate_up` only ever moves forward).

**Known, accepted limitation (not a defect, not fixed)**: like every class/file-based migration framework (Rails, Laravel, Django included), a migration that's been applied and later has its class deleted or renamed can no longer be targeted by `migrate_down`, since the runner only knows about currently-registered classes. This is inherent to the pattern, not specific to this implementation, and doesn't arise here since no migration has been renamed or removed.

## 4. Naming consistency

**Finding, fixed**: the framework namespace `Tube_Core\Migration` (singular — `MigrationRunner`, `MigrationInterface`, etc.) and the concrete-migrations namespace `Tube_Core\Migrations` (plural — `Migration001CreateVideoMetadataTable` etc.) differed by one character. This is a real typo/misread risk in `use` statements that a normal PHPCS pass can't catch (both are syntactically valid, just easy to confuse). Renamed the concrete-migrations namespace to **`Tube_Core\SchemaMigrations`** — the `migrations/` directory itself is unchanged (ARCHITECTURE.md §3 names that directory explicitly), only its PSR-4 namespace mapping changed, in `composer.json`, the three existing migration files' `namespace` declarations, and `Plugin.php`'s imports.

Everything else checked out consistent: table names uniformly `wp_tube_{noun}`, columns uniformly snake_case, classes uniformly PascalCase (the underscore-style naming from the first Phase 1 draft was already caught and fixed within Phase 1 itself, before that commit landed).

## 5. Dependency graph

**Finding: clean.** `Plugin.php` depends only on other `Tube_Core\*` classes and WordPress/WP-CLI globals — zero references to any other `tube-*` plugin, correct for the foundation plugin. Independently re-verified (not just re-read) that `tube-player`/`tube-search`/`tube-seo`/`tube-admin` declare `Requires Plugins: tube-core` and `tube-cache` does not, by grepping all six plugin headers fresh rather than trusting the Phase 0 summary. No plugin's `composer.json` references another plugin as a dependency — each remains independently `composer install`-able, per ARCHITECTURE.md §4's independence requirement.

## 6. Coding standards

Re-ran PHPCS and PHPUnit fresh (not reused from Phase 1) as a regression check, plus an independent script-based scan for any `public`/`protected`/`private function` not preceded by a docblock — a check that doesn't rely on trusting the same PHPCS sniffs that graded Phase 1's own work.

**Finding: clean.** `phpcs` exits 0 across the whole repo; all 13 PHPUnit tests still pass; the independent docblock scan found zero undocumented methods; every file has `declare(strict_types=1)`.

## 7. Future compatibility with 500k+ videos

- All ID/foreign-key-shaped columns are `BIGINT UNSIGNED`, correct headroom for a 500k+ video catalog and the multi-million-row junction tables that implies (500k videos × a handful of actors each).
- `VARCHAR(191)` for indexed slug/name columns is the standard safe width for a `utf8mb4` index under InnoDB's key-length limit — already correct in Phase 1, not something this audit needed to change.
- The junction tables use a composite primary key with no surrogate `id` column — the more scale-efficient choice for a pure many-to-many table (avoids an unindexed-by-default extra column), already correct.
- The one real gap — missing `name_idx` for the admin search pattern §10 requires at scale — is fixed in §2/§4 above.

## 8. Compatibility with ARCHITECTURE.md

Cross-checked column-for-column against §2.1, §2.7, and §14.2 — exact matches, including `cf_status` remaining a true `ENUM` (not relaxed to `VARCHAR`) as specified.

Two places where the implementation reads the architecture's prose rather than a literal spec, surfaced here explicitly rather than left implicit:

1. **§3 says the runner "discovers every active plugin's `migrations/` directory."** Phase 1 implemented explicit self-registration (`register_source()`) instead of filesystem scanning — a deliberate choice (type-safe, fails fast on a bad class name, no fragile filename-to-classname parsing) that serves the same architectural intent §3 describes (a shared runner aggregating plugin-owned migration sets) better than literal directory-globbing would have. Kept as-is; recorded here so it's a known, reviewed decision rather than a silent deviation.
2. **§14.2 gives actor's exact columns; §14.3 says studio "follows the same shape... with parent_id."** It doesn't enumerate studio's exact non-`parent_id` columns. Phase 1 interpreted "same shape" as `description`/`logo_image_id` (studio's analogs to actor's `bio`/`photo_image_id`) and added `website_url`, which has no actor equivalent at all. `website_url` is a reasonable, low-risk, purely additive field for a studio/production-company entity — kept, but flagged here as going slightly beyond a strict reading of "same shape," rather than passed over quietly.

---

## Summary of changes made by this audit

| Change | File(s) | Type |
|---|---|---|
| New migration adding `name_idx` to `wp_tube_actors` and `wp_tube_studios` | `migrations/Migration004AddActorStudioNameIndexes.php` | Additive, reversible schema fix |
| New `drop_index()` helper (dbDelta has no equivalent, same reasoning as the existing `drop_table()`) | `includes/Migration/AbstractMigration.php` | Supporting code for the above |
| Renamed `Tube_Core\Migrations` → `Tube_Core\SchemaMigrations` | `composer.json`, `migrations/Migration00{1,2,3}*.php`, `includes/Plugin.php` | Naming-clarity fix, no behavior change |

All three changes were verified live against the running staging database: `wp tube migrate status` shows migration 004 applied; `SHOW INDEX` confirms `name_idx` exists on both tables; rolling migration 004 back and forward again was tested and produces the exact expected index-only change with zero effect on the other three migrations' tables or data. `phpcs` exits 0 and all 13 PHPUnit tests pass after every change.

No production access. All work stayed in the local Docker staging environment.
