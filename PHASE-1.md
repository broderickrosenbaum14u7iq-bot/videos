# Phase 1 — Tube Core Foundation

Status: **Complete.** Implements exactly ARCHITECTURE.md's Phase 1 scope, updated for the §14 supersession (actor/studio are dedicated tables, not taxonomies), and nothing beyond it — no event dispatcher, no other tables, no other plugins' business logic. That is later phases, per the "never generate multiple phases together" rule.

---

## 1. What was built

### 1.1 Content model (`Tube_Core\Content`)
- `VideoPostType`: registers the `video` CPT exactly per ARCHITECTURE.md §1.1 — `public`, `show_in_rest`, `has_archive`, `supports = [title, thumbnail, excerpt, custom-fields, author]` (no `editor`), `rewrite => ['slug' => 'watch', 'with_front' => false]`. Full labels, i18n via `tube-core` text domain.
- `CategoryTaxonomy` (`video_category`) and `TagTaxonomy` (`video_tag`): native WordPress taxonomies scoped only to `video`, per §1.2 and the §14 decision to keep these two as taxonomies while actor/studio become tables. `video_category` is hierarchical with rewrite slug `category`; `video_tag` is flat with rewrite slug `tag`.
- Content classes do not self-hook. Each exposes one public registration method (`register_post_type()` / `register_taxonomy()`) and is wired to WordPress's `init` action by `Plugin::boot()` — this means each can be instantiated and called directly in a test without triggering WordPress's hook machinery, satisfying "every plugin must be independently testable" at the class level, not just the plugin level.

### 1.2 Migration framework (`Tube_Core\Migration`, `Tube_Core\Database`)
- `MigrationInterface`: `version()`, `description()`, `up()`, `down()` — the shared contract every plugin's migrations follow, per §3.
- `SchemaVersionRepositoryInterface` + `SchemaVersionStore`: the interface exists specifically so `MigrationRunner` doesn't depend on a concrete, WordPress-backed class — this is what makes the runner unit-testable without a database (§1.4 below). `SchemaVersionStore` is the real implementation, backed by `wp_tube_schema_versions`.
- `AbstractMigration`: shared `$wpdb` access, a `dbDelta()`-wrapping `apply_schema()` helper, and a `drop_table()` helper for `down()` methods (dbDelta has no drop equivalent).
- `MigrationRunner`: plugins self-register an ordered list of migration classes via `register_source()` rather than filesystem discovery — explicit and type-safe (an invalid class name fails at parse time). Provides `status()`, `migrate_up()`, and `migrate_down()`, all fully covered by unit tests (§3 below).
- `MigrateCommand` (`wp tube migrate status|up|down`): matches ARCHITECTURE.md §3's CLI surface exactly, including the rule that `down` requires both `--plugin` and `--to` explicitly (no accidental project-wide rollback).

### 1.3 Tables created (all via migrations, all reversible)

| # | Table(s) | Migration | Notes |
|---|---|---|---|
| — | `wp_tube_schema_versions` | N/A — created directly by `SchemaVersionStore::install()` | The one table outside the migration system, since it's the system's own bookkeeping (§2.7) |
| 001 | `wp_tube_video_metadata` | `Migration001CreateVideoMetadataTable` | Typed 1:1 extension table replacing `wp_postmeta` for video data, per §2.1. Stores the Cloudflare Stream UID only — never a URL |
| 002 | `wp_tube_actors`, `wp_tube_video_actors` | `Migration002CreateActorTables` | Dedicated tables per §14, superseding an `actor` taxonomy |
| 003 | `wp_tube_studios`, `wp_tube_video_studios` | `Migration003CreateStudioTables` | Dedicated tables per §14, superseding a `studio` taxonomy; `parent_id` preserves the hierarchical relationship |

Every `down()` was verified to actually drop what its `up()` created — not assumed (see §4).

### 1.4 `Requires Plugins` wiring
Already declared in each dependent plugin's header since Phase 0; this phase re-verified the gate holds with real code loaded (not just stub files), by deactivating `tube-core` and confirming WordPress itself blocks `tube-player`'s activation with "Tube Player requires 1 plugin to be installed and activated: Tube Core."

---

## 2. Design decisions made during implementation

Two real, empirically-discovered conflicts required decisions beyond what Phase 0 had already resolved — both are documented inline in `phpcs.xml`, not just here:

1. **PSR-1's camelCase method-name mandate vs. WordPress's snake_case API.** This codebase's methods sit directly alongside `register_post_type()`, `add_action()`, `register_taxonomy()`, etc. — camelCase methods wrapping snake_case WordPress calls throughout would hurt readability more than PSR-1's rule is meant to protect. Decision: snake_case method names throughout, `PSR1.Methods.CamelCapsMethodName` excluded, reasoning documented in `phpcs.xml`.
2. **A cluster of "padded" formatting sniffs** (short-array-syntax disallowal, array-bracket padding, function-declaration-parenthesis padding, cast-structure spacing colliding with PSR-12's control-structure spacing) pulled in transitively via PHPCSExtra's "Universal" collection and Squiz — all contradicting both PSR-12 and this project's PHP-8.3-only style. Resolved the same way as Phase 0's tab/brace conflicts: PSR-12 wins on layout, each exclusion documented with the specific empirical conflict that justified it.

Also carried forward from Revision 4 of the architecture and applied concretely here: `wp_tube_video_metadata.cf_status` is a real `ENUM` column (not `VARCHAR`) as the architecture doc specifies, and no foreign key constraints exist anywhere — `dbDelta()` doesn't support them reliably and WordPress core itself never uses FKs on `wp_posts` for the same reason, so referential integrity is enforced at the application layer, consistent with WordPress convention.

---

## 3. Automated tests

**13 PHPUnit unit tests, 22 assertions, all passing**, covering `MigrationRunner`'s full orchestration logic: applying in order, idempotency, single-plugin targeting, `--to`-version targeting, rollback in reverse order, rollback stopping before (not at) the target, rejecting unregistered plugins/versions, and `status()` reporting. These run against a fake `SchemaVersionRepositoryInterface` and fake migration classes — zero WordPress or database dependency, so they run in milliseconds and are what "independently testable" concretely means for this class.

**Scoping decision, stated plainly rather than left implicit**: this phase does not include a full `WP_UnitTestCase`-based integration test suite (real CPT/taxonomy registration and real migrations exercised against a WordPress-bootstrapped test database via the official WP PHPUnit test library). That infrastructure — `install-wp-tests.sh`, a dedicated test database, WordPress's test-suite checkout — is a genuine separate undertaking. In its place, §4 below is real, live verification against the actual running staging WordPress instance: every piece of behavior a `WP_UnitTestCase` suite would check (CPT registered with correct args, taxonomies attached, tables created with the exact specified schema, migrations reversible) was directly exercised and observed via WP-CLI against the live database, not assumed. Building the formal `WP_UnitTestCase` suite is flagged as future work, best done once there's more registered-content behavior across more plugins to justify the setup cost.

---

## 4. Verification evidence (live staging)

All of the following were actually run against the Docker staging stack, not assumed:

| Check | Result |
|---|---|
| Deactivate/reactivate `tube-core` (forces `activate()` to run with real code) | No fatal errors |
| `wp post-type list` | `video` present |
| `wp taxonomy list` | `video_category`, `video_tag` present; no `actor`/`studio` taxonomy (correctly superseded by tables) |
| `SHOW TABLES LIKE 'wp_tube%'` | All 6 tables present: `schema_versions`, `video_metadata`, `actors`, `video_actors`, `studios`, `video_studios` |
| `wp tube migrate status` | All 3 tube-core migrations shown as applied, with real timestamps |
| `DESCRIBE wp_tube_video_metadata` / `wp_tube_actors` | Column-for-column match against ARCHITECTURE.md §2.1 / §14.2 |
| Create a real `video` post, enable pretty permalinks | URL resolves to `/watch/test-video-one/` exactly as specified in §15.1 |
| `curl` the video page and `/wp-json/wp/v2/videos` | Both `HTTP 200` |
| **Rollback**: `wp tube migrate down --plugin=tube-core --to=001` | `wp_tube_actors`/`wp_tube_video_actors`/`wp_tube_studios`/`wp_tube_video_studios` dropped; `wp_tube_video_metadata` and `wp_tube_schema_versions` untouched; `status` correctly shows 002/003 as not applied |
| **Re-apply**: `wp tube migrate up --plugin=tube-core` | All 4 tables recreated with the same schema; `status` shows all 3 applied again |
| `Requires Plugins` gate, re-verified with real code | Deactivating `tube-core` then attempting `wp plugin activate tube-player` is blocked by WordPress itself with the expected message |
| `./vendor/bin/phpcs --standard=phpcs.xml` (whole repo) | Exit code `0` — zero errors, zero warnings |
| `./vendor/bin/phpunit` (tube-core) | 13/13 passing |

### Incidental fix (not Phase 1 scope, but found and fixed while verifying)
A duplicate `DISABLE_WP_CRON` define was firing a PHP warning on every request — a leftover from Phase 0's manual `wp config set` fix colliding with `docker-compose.yml`'s `WORDPRESS_CONFIG_EXTRA`. Removed the redundant hardcoded line from `wp-config.php` and moved `WORDPRESS_CONFIG_EXTRA` into a shared YAML anchor (`x-wordpress-env`) so the `wordpress`, `wpcli`, and `cron` services all see identical config — previously only `wordpress` had it, which made `wp-cli` itself misreport `DISABLE_WP_CRON` as unset even though the live site had it correctly enabled. Verified via a fresh container restart producing zero warnings in the log.

---

## 5. Explicitly out of scope for Phase 1

No event dispatcher (Phase 2), no `tube-cache` Redis integration (Phase 3), no `video_views`/`video_statistics`/`import_queue`/`watch_history` tables (Phases 4–5), no `tube-player`/`tube-search`/`tube-seo`/`tube-admin` business logic, no theme templates. All per ARCHITECTURE.md §12, none of it started here.

## 6. Production impact

None. All work happened in the local Docker staging environment. The production server (`root@139.99.96.155:/www/wwwroot/phimtoico.org`) was not accessed or modified.
