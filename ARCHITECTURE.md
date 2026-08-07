# Tube Site — Technical Architecture Document (Revision 5)

Status: **Approved and Frozen**, effective before Phase 3. Implementation is underway, phase by phase, exactly as defined in §12 below. This document is not changed to reflect implementation progress — for what's actually been built and verified, read the per-phase `PHASE-X.md` files (`PHASE-0.md`, `PHASE-1.md`, `PHASE-1-AUDIT.md`, `PHASE-2.md`, ...) and `git log`, not this file. It *was* changed twice, deliberately and in full view, before the freeze — see §19 and `ARCHITECTURE-CHANGELOG.md` for Revision 5's changes, made after Phases 0–2 were already implemented, without invalidating any of them (confirmed in `ARCHITECTURE-OPTIMIZATION-REVIEW.md`'s Migration Impact Report). **After the freeze, it does not change except through the ADR process in `DEVELOPMENT_RULES.md` §8** — see `ARCHITECTURE_FREEZE.md` for exactly what's frozen, flexible, and deferred.

**Before doing any implementation work, also read `DEVELOPMENT_RULES.md`** — process rules (one phase at a time, wait for approval, backward compatibility, the Architecture Regression Review, Architecture Drift Report, and Hostile Pre-Commit Review required around every phase, etc.) live there, not here, and are binding regardless of what any individual conversation does or doesn't recall. Sessions have no memory of prior conversations; both files are the source of truth, not this document's revision history or any chat transcript.

This revision (4): eliminates `wp_postmeta` for video data entirely (dedicated `wp_tube_video_metadata` table), removes WP-Cron completely in favor of Linux cron + WP-CLI, adds a formal migration/rollback framework shared by every plugin, formalizes REST API versioning, adds an internal event system, adds a search indexing layer, adds an image management architecture, and locks the codebase to PHP 8.3 with `declare(strict_types=1)`, PSR-12, and WordPress Coding Standards.

Note: §14 revised the actor/studio design from taxonomies to dedicated tables (superseding earlier references to actor/studio taxonomies in §1); as-built table schemas were subsequently amended once more by the Phase 1 audit (a `name_idx` added via a later migration, not by editing §14's text) — see `PHASE-1-AUDIT.md`.

Revision 5 (§19): a small number of decisions were sharpened after an explicit, adversarial re-challenge of the whole architecture — most notably, a previously-proposed generic service container was considered and **rejected** as unneeded complexity. Full reasoning in `ARCHITECTURE-OPTIMIZATION-REVIEW.md`; every accepted change is logged in `ARCHITECTURE-CHANGELOG.md`.

---

## 1. Core content model

Unchanged from Revision 2: a dedicated `video` Custom Post Type (native `post` is not used), with four taxonomies scoped only to it — `video_category`, `video_tag`, `actor`, `studio`. See Revision 2 for the full CPT registration shape; nothing here changes it.

What changes is where video-specific data lives.

---

## 2. Database design

Six dedicated tables, all owned by a specific plugin, none accessed via `WP_Query`/postmeta APIs. Every raw query against these tables uses `$wpdb->prepare()`.

### 2.1 `wp_tube_video_metadata` — replaces `wp_postmeta` entirely for video data

**`wp_postmeta` is not used for video data at all** — not even for the small, low-cardinality attributes Revision 2 allowed there (Cloudflare Stream UID, duration, status). Reasoning: `wp_postmeta` is an EAV (entity-attribute-value) table — every field is an untyped string row requiring a join and a `meta_key` string match. At 500,000+ videos, that's 500,000+ rows per meta key, no real type safety, and no way to add a proper index for, say, "all videos with a given `cf_status`" without a very wide, inefficient index. A dedicated **wide table** (one row per video, real typed columns) is both simpler and faster:

```sql
CREATE TABLE wp_tube_video_metadata (
  video_id                 BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  cf_stream_uid             VARCHAR(64) NOT NULL,
  cf_status                 ENUM('pending','processing','ready','error') NOT NULL DEFAULT 'pending',
  duration_seconds          INT UNSIGNED NULL,
  thumbnail_time_seconds    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  poster_image_id           BIGINT UNSIGNED NULL,     -- see §8, image management
  og_image_id               BIGINT UNSIGNED NULL,
  schema_version             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at                 DATETIME NOT NULL,
  updated_at                 DATETIME NOT NULL,
  UNIQUE KEY cf_stream_uid_idx (cf_stream_uid),
  KEY cf_status_idx (cf_status)
) ENGINE=InnoDB;
```

- `video_id` is the primary key — a strict 1:1 extension of the `video` CPT row, joined only when metadata is actually needed.
- `cf_status_idx` makes "all videos still processing" or "all videos in error state" — an operational query `tube-admin` needs constantly — a fast indexed lookup instead of a postmeta scan.
- **This table stores only the Cloudflare Stream UID — never a playback URL**, consistent with Revision 2's requirement; `tube-player` is still the only plugin that turns a UID into a URL, and it does so at render time.
- `poster_image_id` / `og_image_id` reference the image management layer (§8), not raw URLs either.

### 2.2 `wp_tube_video_views` — write-optimized ingest table
Unchanged from Revision 2: hourly-bucketed counters, Redis-buffered writes, monthly partitions, retention/rotation job. See §7 (background jobs) for how the flush now runs — via Linux cron, not WP-Cron.

### 2.3 `wp_tube_video_statistics` — read-optimized rollup table
Unchanged from Revision 2: one row per video, pre-aggregated `views_total`/`views_today`/`views_7d`/`views_30d`, indexed for `ORDER BY ... LIMIT` queries. Rollup cadence now driven by Linux cron (§7).

### 2.4 `wp_tube_import_queue` — durable bulk-import pipeline
Unchanged from Revision 2. Batch processing now runs exclusively via a Linux-cron-invoked WP-CLI command (§7), never WP-Cron, including the initial 500k-video backfill.

### 2.5 `wp_tube_watch_history` — per-viewer progress
Unchanged from Revision 2. Guest-row purge now runs via Linux cron + WP-CLI (§7).

### 2.6 `wp_tube_search_index` — new: denormalized search/listing index

```sql
CREATE TABLE wp_tube_search_index (
  video_id           BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  title              VARCHAR(255) NOT NULL,
  description        TEXT NULL,
  category_ids       VARCHAR(255) NULL,   -- JSON array of term IDs
  tag_ids            VARCHAR(255) NULL,
  actor_ids          VARCHAR(255) NULL,
  studio_ids         VARCHAR(255) NULL,
  duration_seconds   INT UNSIGNED NULL,
  views_total        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  published_at       DATETIME NULL,
  indexed_at         DATETIME NOT NULL,
  FULLTEXT KEY search_text_idx (title, description),
  KEY published_idx (published_at),
  KEY views_total_idx (views_total)
) ENGINE=InnoDB;
```

- Owned by `tube-search`, not `tube-core` — this is the plugin's own storage, populated by copying (denormalizing) data from the CPT, taxonomies, and `video_statistics` rather than joining them live at query time. That denormalization is the entire point of a search index: filtered, sorted, full-text queries across 500,000+ videos without a multi-table join on every request.
- Kept in sync two ways: (1) **incrementally**, a single-row upsert triggered by the event system (§6) the instant a video is published/updated/deleted — cheap enough to run inline; (2) **in bulk**, a full reindex via Linux cron + WP-CLI (`wp tube-search index:rebuild`) that corrects any drift and is what actually populates the index after a large import run completes.
- Structured specifically so the *storage* can be swapped for OpenSearch/Elasticsearch/Meilisearch later without any other plugin knowing — `tube-search` exposes only query methods (`Tube_Search\Query::find()`), never this table's schema, to its own consumers.

### 2.7 `wp_tube_schema_versions` — shared migration tracking (see §3)

```sql
CREATE TABLE wp_tube_schema_versions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plugin_slug   VARCHAR(50) NOT NULL,
  version       VARCHAR(20) NOT NULL,
  applied_at    DATETIME NOT NULL,
  UNIQUE KEY plugin_version (plugin_slug, version)
) ENGINE=InnoDB;
```

Created by `tube-core` (it must exist before any plugin's migrations can run) and shared by every plugin's migration runner as the single source of truth for "what schema version is each plugin currently at."

---

## 3. Migration & rollback framework

Every plugin — not just `tube-core` — follows the same migration contract, so schema evolution is handled identically project-wide instead of six different ad hoc approaches.

- Each plugin ships a `migrations/` directory containing one file per version (e.g. `migrations/001-create-video-metadata-table.php`), each defining an `up()` and a `down()` method against a shared migration interface.
- A single WP-CLI-driven runner (provided by `tube-core`, since it loads first and owns `wp_tube_schema_versions`) discovers every active plugin's `migrations/` directory and applies pending migrations in order, recording each applied version in `wp_tube_schema_versions`.
- **Rollback is a first-class operation**, not an afterthought: every migration's `down()` must reverse its own `up()` exactly (drop what it created, restore what it altered), so a bad deploy can be walked back to a known-good schema version per plugin.
- Commands (all WP-CLI, no admin-UI migration runner — schema changes are a deploy-time operation, not a runtime one):
  - `wp tube migrate status` — shows current version per plugin vs. latest available
  - `wp tube migrate up [--plugin=<slug>] [--to=<version>]` — applies pending migrations (all plugins, or one, optionally stopping at a target version)
  - `wp tube migrate down --plugin=<slug> --to=<version>` — rolls a specific plugin back to a target version (explicit `--plugin` required for `down`, to avoid an accidental project-wide rollback)
- A plugin with no tables of its own (e.g. `tube-seo`, which may only need options) still implements the same interface with an empty migration set — this is what "all plugins support schema migrations" means in practice: one consistent mechanism available to every plugin, used by however many of them actually need it, including ones that only need it later.

---

## 4. Plugin architecture

Six independent plugins, unchanged in their core split from Revision 2, with two updates: `tube-search` now owns its own table (§2.6), and every plugin follows the shared migration contract (§3).

```
tube-core     ← foundation: CPT, taxonomies, video_metadata, video_views,
                 video_statistics, import_queue, watch_history, event dispatcher,
                 schema_versions table + migration runner
   ↑
   ├── tube-player   (playback + image rendering; no tables)
   ├── tube-search    (search_index table; query layer)
   ├── tube-seo       (meta/schema/sitemap; no tables, uses migration contract for future options schema)
   └── tube-admin     (wp-admin UI; no tables)

tube-cache    ← Redis/edge cache infra; no MySQL tables; independent utility
```

Plugin-to-plugin communication happens two ways, deliberately kept distinct:
- **Synchronous reads** (theme asking "give me this video's data") go through each plugin's documented public API, as in Revision 2.
- **Reactions to state changes** (cache purge, search index update, admin notification) go through the event system (§6) instead of direct calls — this is the main structural change in this revision, and it further decouples the plugins: `tube-core` no longer needs to know `tube-search` or `tube-cache` exist in order to fire a "video published" event; they simply subscribe.

`Requires Plugins: tube-core` remains declared in every dependent plugin's header (native WP 6.5+ dependency support).

---

## 5. Theme: presentation only

Unchanged in principle from Revision 2 — zero `$wpdb`, zero direct queries, only documented template-tag calls into the plugins. Two additions to the theme's allowed API surface for this revision:

| Theme needs | Calls |
|---|---|
| Responsive poster/thumbnail `<img>` (with WebP) | `tube_player_get_image_html( $video_id, $size )` (§8) |
| Search results page | `tube_search_query( $args )` — now backed by `wp_tube_search_index`, not a live join |

Everything else from Revision 2's theme contract table still applies unchanged.

---

## 6. Internal event system

A documented, typed event catalog built on top of WordPress's action hook mechanism (so it stays compatible with core tooling) but with a stable, versioned contract other plugins can rely on instead of guessing hook names by convention.

- Owned by `tube-core`, exposed as `Tube_Core\Events\Dispatcher::dispatch( string $event, array $payload )` and `Dispatcher::listen( string $event, callable $handler )`.
- Every event name and payload shape is documented centrally (an `EVENTS.md`-equivalent maintained alongside the code) — this is the same kind of explicit contract as the REST API and the template-tag functions, just for internal pub/sub.

**Core event catalog (initial):**

| Event | Fired by | Typical subscribers |
|---|---|---|
| `video.created` | tube-core (on CPT insert) | tube-search (index insert) |
| `video.updated` | tube-core | tube-search (index update), tube-cache (purge) |
| `video.published` | tube-core | tube-search (index update), tube-cache (purge), tube-seo (sitemap flag) |
| `video.deleted` | tube-core | tube-search (index delete), tube-cache (purge) |
| `video.stream.status_changed` | tube-core (from Cloudflare webhook) | tube-admin (dashboard update) |
| `video.view.recorded` | tube-core | (internal only — stats rollup reads the table directly, not the event) |
| `video.stats.rolled_up` | tube-core (end of rollup job) | tube-search (refresh `views_total` in index) |
| `import.item.completed` | tube-core | tube-admin (dashboard), tube-search (index insert) |
| `import.item.failed` | tube-core | tube-admin (alert/dashboard) |

- Event handlers that do meaningful work (a full index rebuild, a bulk cache purge) should stay fast or delegate to a queued/cron-driven follow-up — the event system is for decoupling *what* reacts to *what*, not a replacement for the background-job system in §7.

---

## 7. Background jobs: Linux cron + WP-CLI only

**WP-Cron is disabled entirely** (`define( 'DISABLE_WP_CRON', true );`) and is not used anywhere in this project's own code. Every recurring task is a WP-CLI command invoked directly by the server's real crontab — no HTTP-triggered pseudo-cron, no reliance on site traffic to fire scheduled events.

| WP-CLI command | Owner | Cadence (Linux crontab) | Purpose |
|---|---|---|---|
| `wp tube-core views:flush` | tube-core | `* * * * *` | Flush Redis view counters into `wp_tube_video_views` |
| `wp tube-core stats:rollup` | tube-core | `*/5 * * * *` | Recompute `wp_tube_video_statistics` from recent partitions |
| `wp tube-core stats:rollup --full` | tube-core | `0 3 * * *` (nightly) | Full recompute, corrects drift |
| `wp tube-core import:process` | tube-core | `* * * * *` (steady-state); run continuously/manually for the initial 500k backfill | Claim + process pending `import_queue` rows |
| `wp tube-core views:partition-maintenance` | tube-core | `0 2 * * *` (nightly) | Create next month's partition, drop expired ones |
| `wp tube-core watch-history:purge` | tube-core | `0 4 * * *` (nightly) | Purge stale guest `visitor_token` rows |
| `wp tube-search index:rebuild` | tube-search | `0 1 * * *` (nightly) | Full search index consistency rebuild |
| `wp tube-seo sitemap:generate` | tube-seo | `0 */1 * * *` (hourly) | Regenerate the video XML sitemap |
| `wp cron event run --due-now` | WordPress core | `*/5 * * * *` | Safety net for any core/third-party code that still schedules via the WP-Cron API internally, now that the HTTP pseudo-cron trigger is disabled |
| `wp tube migrate up` | tube-core | on-deploy only, not scheduled | Applies pending schema migrations as part of the deploy process |

This table is also the concrete deliverable for Phase 0/1 infra setup — it's the actual crontab that gets installed on the server.

---

## 8. Image management architecture (poster, thumbnails, WebP)

Two-tier design, mirroring the "store IDs, not URLs" principle already applied to video:

1. **Default poster (the common case, ~all 500,000+ videos)**: no image is stored or uploaded at all. `tube-player` requests a thumbnail directly from Cloudflare Stream's thumbnail endpoint using the stored `cf_stream_uid` and `thumbnail_time_seconds` (from `wp_tube_video_metadata`), at render time, with size/format parameters. Zero storage cost, zero upload workflow, scales to the full catalog for free.
2. **Custom poster override (the exception)**: for the minority of videos where an editor wants a hand-picked image instead of an auto-extracted frame, the image is uploaded through `tube-admin`'s editorial UI and stored in **Cloudflare Images** (not the local WordPress media library) — for the same reason video isn't stored locally: at this scale, generating and storing multiple local derivative sizes per image (WordPress's default behavior on upload) would mean millions of physical files on the origin server. Only the Cloudflare Images ID is stored, in `wp_tube_video_metadata.poster_image_id` / `og_image_id` — never a URL.

**Delivery**: `tube-player` constructs the actual `<img>`/`<picture>` markup at render time via `tube_player_get_image_html( $video_id, $size )`, requesting the appropriate variant (grid-card, hero, OG-image) and letting Cloudflare's `format=auto` content negotiation serve WebP/AVIF to browsers that support it and JPEG as a fallback — no format conversion or resizing logic lives in WordPress at all. Responsive `srcset` is generated from Cloudflare's resizing variants, not from locally-generated intermediate sizes.

This is why image management is owned by `tube-player` (rendering) with `tube-admin` only providing the upload UI for the override case — there's no dedicated image-processing plugin because there's deliberately very little image *processing* happening on the WordPress server itself.

---

## 9. REST API design & versioning

Namespace: `tube/v1`, split by owning plugin as in Revision 2 (see that table for the full route list — unchanged). This revision formalizes the versioning policy itself:

- **`/tube/v1` is additive-only.** New fields may be added to existing responses and new routes may be added under `v1` without a version bump, as long as no existing field is removed or changes meaning/type.
- **Any breaking change requires `/tube/v2`**, served alongside `/tube/v1` for a defined deprecation window (not an in-place replacement) — old clients (cached HTML with embedded API calls, third-party integrations) keep working until `v1` is formally sunset.
- Each plugin registers only routes under its own logical slice of `tube/v1` (`tube/v1/videos*`, `tube/v1/videos/{id}/view`, `tube/v1/admin/*`, etc.) — there is no shared "router" plugin; `tube-core` merely owns the namespace string, and route-naming collisions are prevented by each plugin owning a distinct URL prefix, documented alongside the event catalog and template-tag contract as one of this project's three internal "public contracts."
- Deprecation, once `v2` exists, is signaled via a `Deprecation`/`Sunset` HTTP response header on `v1` routes (per the emerging IETF draft convention), not silent removal.

---

## 10. Designing for 500,000+ videos and millions of pageviews

Unchanged from Revision 2 (§6 there), with one addition specific to this revision: the search index table (§2.6) is what makes filtered/sorted browsing and text search viable at this scale without live joins across the CPT, taxonomy tables, and `video_statistics` on every request — this was implicit in "search indexing layer" as a requirement and is now an explicit part of the scale story, not just a search-feature story.

---

## 11. Coding standards

- **PHP 8.3 only** — no backward compatibility shims for earlier versions; this matches the production server's active PHP-FPM version confirmed during the earlier server audit. Enables full use of readonly properties, native enums, and first-class callable syntax throughout.
- **`declare(strict_types=1);`** as the first statement in every PHP file across all six plugins and the theme — no loose type coercion anywhere in this codebase.
- **Full type declarations** on every method/function: parameter types, return types (including `void`/`never` where applicable), typed class properties. Status-like fields (`cf_status`, `import_queue.status`) are represented in PHP as native backed `enum`s (e.g. `enum CfStreamStatus: string`), even though the underlying MySQL column remains a plain `ENUM`/`VARCHAR` — the enum lives in code, not just in the schema.
- **PSR-12** governs code layout and formatting (indentation, brace placement, `use` statement ordering, line length). **WordPress Coding Standards (`WordPress-Extra` + `WordPress-Docs`)** governs WordPress-specific concerns — output escaping, input sanitization, i18n, nonce/capability checks, direct-DB-query justification comments. These two rulesets don't meaningfully conflict: PSR-12 is about formatting, WPCS is about WordPress security/i18n conventions, and both run together via a single combined PHPCS ruleset.
- **Composer-based PSR-4 autoloading per plugin**, namespaced classes in StudlyCaps (`Tube_Core\Repositories\VideoMetadataRepository`), one class per file. This is a deliberate, explicit departure from the legacy WPCS file-naming convention (`class-name-like-this.php`) in favor of modern PSR-4 file-per-namespace layout — appropriate here because the project is PHP-8.3-only, strictly typed, and Composer-driven from the start rather than a traditional loosely-typed WP plugin. WPCS is still enforced for everything it's actually meant to catch (escaping, sanitization, i18n, SQL preparation); the file-naming sniff specifically is disabled in favor of PSR-4 in the shared PHPCS ruleset.
- PHPCS (combined PSR-12 + WordPress-Extra ruleset) wired into CI, blocking merge on violation.

---

## 12. Implementation phases (revised)

| Phase | Deliverable |
|---|---|
| 0 | Staging environment, git repo structure, Composer + PHPCS (PSR-12 + WPCS) tooling, CI, Linux crontab skeleton (§7, initially pointing at no-op commands) |
| 1 | `tube-core` foundation: CPT, taxonomies, `wp_tube_schema_versions` + migration runner (with rollback), `wp_tube_video_metadata` table, `Requires Plugins` wiring |
| 2 | `tube-core`: event dispatcher (§6) — needed before cache/search can subscribe to anything |
| 3 | `tube-cache`: Redis connection layer, caching API, rate-limit helper, event subscriber for `video.published`/`video.updated`/`video.deleted` (purge) |
| 4 | `tube-core`: `video_views` + `video_statistics` tables, Redis-buffered view recording, stats rollup — driven entirely by the Linux-cron commands in §7 |
| 5 | `tube-core`: `import_queue` table, batch processor, WP-CLI bulk-import command, Cloudflare Stream webhook handling, `watch_history` table + API |
| 6 | `tube-player`: Stream URL construction from UID, click-to-load embed, image management (§8) — default Stream thumbnails + Cloudflare Images override path |
| 7 | `tube-search`: `wp_tube_search_index` table + migration, event-driven incremental sync, `index:rebuild` WP-CLI command, query API |
| 8 | Theme: presentation layer against the plugin template-tag APIs from phases 1–7, plus `tube-seo`'s title/meta description/canonical/robots/OpenGraph/Twitter Cards/JSON-LD (`VideoObject`/`BreadcrumbList`/`CollectionPage`)/pagination metadata — pulled forward from this row's original Phase 9 scope; see `PHASE-8.md` and `ARCHITECTURE-CHANGELOG.md` |
| 9 | `tube-seo`: video XML sitemap generation (Linux-cron driven, `wp tube-seo sitemap:generate`, §7) — the one piece of this row's original scope not delivered in Phase 8 |
| 10 | `tube-admin`: import dashboard, statistics dashboard, custom-poster upload UI, bulk tools, settings UI |
| 11 | Scale hardening: read-replica routing, partition rollout/retention verification, load test at simulated 500k-video / high-pageview volume, edge cache tuning |
| 12 | QA, security review (REST auth/nonces, `$wpdb->prepare()` audit across all six tables, migration rollback drill), staging → production cutover |
| 13 | `tube-theme`: full production UI (dark theme, hero, mega menu, infinite scroll, actor/studio pages, modern search) — plus minimal additive `tube-core`/`tube-player` template tags the presentation layer needed (actor/studio listing + bulk lookup, actor/studio photo rendering). User-commissioned post-1.0.0 phase, not part of this table's original 0–12 scope; see `PHASE-13.md` and `ARCHITECTURE-CHANGELOG.md` |

---

## 13. Open decisions for your review

Carried forward from Revision 2 (still unresolved) plus one new item:

1. **Watch history scope** — logged-in users only, or also anonymous via cookie token?
2. **Search backend timing** — `wp_tube_search_index` on MySQL FULLTEXT to start (as designed), or commit to OpenSearch/Elasticsearch as the index's backing store from Phase 7 directly?
3. **Read replica infrastructure** — does the current single VPS support adding a replica, or does this require new infrastructure ahead of Phase 11?
4. **Repo structure** — one monorepo for all six plugins + theme, or seven separate repositories? Affects Composer/CI setup in Phase 0.
5. **New: Cloudflare Images vs. local media library for custom poster overrides (§8)** — recommended default is Cloudflare Images for consistency/scale with the video storage decision, but this adds a second Cloudflare product/cost to confirm before Phase 6.

This section (§13) and its predecessors are carried forward unchanged from Revision 3. What follows is new content added for this final revision.

---

## 14. WordPress taxonomies vs. dedicated actor/studio tables

Revision 3 (and all prior revisions) modeled `actor` and `studio` as WordPress taxonomies on the `video` CPT. Before implementation begins, that choice needs to be checked against the 500,000+ video target specifically — this is the one piece of the content model that hadn't yet been through the same scrutiny as views, stats, imports, and search.

### 14.1 Comparison

| | WordPress taxonomies (Revision 3 design) | Dedicated tables |
|---|---|---|
| Storage | `wp_terms` / `wp_term_taxonomy` / `wp_term_relationships` — **shared** with `video_category`, `video_tag`, and any other taxonomy on the install | `wp_tube_actors`, `wp_tube_studios`, `wp_tube_video_actors`, `wp_tube_video_studios` — dedicated, purpose-built |
| Term metadata (bio, photo, birth date, studio logo) | `wp_termmeta` — the same EAV pattern this project already rejected for video data in §2.1, for the same reasons | Real typed columns |
| Relationship cardinality | Actor/studio are a **high-cardinality, many-per-video, tag-like** relationship (a video may credit several actors) — exactly the pattern that stresses `wp_term_relationships` and WordPress's per-term-per-save count recalculation (`_update_post_term_count`, which runs a `COUNT` query per attached term on every save) most heavily | A relationship table scoped only to video↔actor and video↔studio, indexed for exactly two access patterns ("videos for this actor," "actors for this video"), with no automatic per-save recount |
| Multi-term filtering (e.g. "videos with Actor A AND Actor B") | `tax_query` with `relation => 'AND'` generates self-joins that degrade notably past moderate row counts — a known, frequently-documented WordPress scaling pain point | A plain indexed join on the relationship table; query shape is fully controlled |
| Read-path impact at scale | Largely mitigated already — `wp_tube_search_index` (§2.6) denormalizes `actor_ids`/`studio_ids` for listing/filtering, so the expensive taxonomy join is mostly bypassed for grid/search reads regardless of which storage backs it | Same denormalization into the search index either way |
| Write-path impact at scale | Every video save touching actor/studio terms re-triggers WordPress's term-count recalculation across all attached terms — this is **not** bypassed by the search index, since it happens on the relationship-write side, not the read side | No equivalent hidden recalculation; counts (if needed for display, e.g. "42 videos") are maintained explicitly, on our terms, likely as a column updated by the same event that updates the search index |
| URL/archive routing | Automatic taxonomy archive pages and rewrite rules, "for free" | Requires custom rewrite rules (see §15) — more code, full control |
| Admin UI | Native term edit screens, but still need AJAX-searchable pickers at this scale regardless (already planned) | Custom CRUD UI in `tube-admin` — was already going to be custom regardless, since term meta boxes alone don't cover bio/photo fields |
| Consistency with the rest of this architecture | The one remaining place still using WordPress's native EAV/shared-table pattern, after every other scale-sensitive concern (views, stats, search, imports) was deliberately moved to dedicated tables | Consistent with the dedicated-table pattern used everywhere else in this project |

### 14.2 Recommendation

**Use dedicated tables for `actor` and `studio`.** Keep `video_category` and `video_tag` as native WordPress taxonomies — their cardinality profile (few per video, moderate total term count, genuinely benefiting from hierarchical category support) doesn't create the same problem, and native taxonomy archive routing is a real, uncomplicated win for those two specifically.

**Why**: actor/studio are precisely the taxonomy usage pattern WordPress handles worst at scale — high per-post cardinality with a write-side cost (term count recalculation) that isn't solved by the search index the way the read-side cost is. This project has already built dedicated tables for every other scale-sensitive concern; leaving actor/studio as taxonomies would be the one inconsistent, most-likely-to-bottleneck piece of an otherwise scale-first design.

**Resulting schema** (owned by `tube-core`, alongside the tables in §2):

```sql
CREATE TABLE wp_tube_actors (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(191) NOT NULL,
  slug          VARCHAR(191) NOT NULL,
  bio           TEXT NULL,
  photo_image_id BIGINT UNSIGNED NULL,
  video_count   INT UNSIGNED NOT NULL DEFAULT 0,   -- maintained explicitly, not auto-recalculated on every save
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  UNIQUE KEY slug_idx (slug)
) ENGINE=InnoDB;

CREATE TABLE wp_tube_video_actors (
  video_id  BIGINT UNSIGNED NOT NULL,
  actor_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (video_id, actor_id),
  KEY actor_video_idx (actor_id, video_id)
) ENGINE=InnoDB;
```

(`wp_tube_studios` / `wp_tube_video_studios` follow the same shape, with `studio` additionally supporting a `parent_id` for the hierarchical relationships Revision 3 gave it as a taxonomy.)

### 14.3 Consequences for earlier sections

This supersedes the taxonomy-based description of `actor`/`studio` in §1 and the taxonomy-ID references to them in §2.6 and §4 of the prior revisions — `wp_tube_search_index.actor_ids`/`studio_ids` now denormalize IDs from these new tables rather than `wp_term_taxonomy` IDs. `video_category` and `video_tag` are unaffected. Implementation should follow this section, not the taxonomy description in earlier revisions.

---

## 15. URL and permalink architecture

### 15.1 URL structure

| Content | Pattern | Routing mechanism |
|---|---|---|
| Video single | `/watch/{video-slug}/` | Native CPT rewrite (`video` CPT, `rewrite => ['slug' => 'watch']`) |
| Category archive | `/category/{category-slug}/`, paginated `/category/{slug}/page/{n}/` | Native taxonomy rewrite (`video_category`) |
| Tag archive | `/tag/{tag-slug}/`, paginated `/tag/{slug}/page/{n}/` | Native taxonomy rewrite (`video_tag`) |
| Actor archive | `/actor/{actor-slug}/`, paginated `/actor/{slug}/page/{n}/` | **Custom** `add_rewrite_rule()` in `tube-core` (per §14, no longer a taxonomy) → `tube_actor` query var → custom `template_include` routing |
| Studio archive | `/studio/{studio-slug}/`, paginated `/studio/{slug}/page/{n}/` | Same pattern as actor, `tube_studio` query var |
| Search results | `/search/{query}/` | Custom rewrite → `tube_search_q` query var, served by `tube-search` |
| REST API | `/wp-json/tube/v1/...` | Standard WP REST routing (§9) |

### 15.2 Canonical policy

- **Video pages**: canonical is always the bare `/watch/{slug}/` URL — any tracking/query parameters (`?ref=`, `?utm_*`, autoplay flags) are stripped for canonical purposes, never included.
- **Paginated archive pages** (category/tag/actor/studio, page 2+): each page is **self-canonical** to its own URL — page 2 canonicalizes to page 2, not collapsed to page 1. This preserves indexability of deep catalog pages, which matters directly for a 500,000+-video catalog.
- **Infinite scroll**: has no separate canonical concern of its own, because infinite scroll is progressive enhancement over the real paginated URLs above (established in earlier revisions) — as the user scrolls, `history.pushState` moves the browser URL to the next real paginated URL, and that URL's own self-canonical rule applies. There is never a URL representing "page 1 plus some AJAX-appended items" as a distinct indexable state.
- **Listing pages are never alternate canonicals for a video.** A video reachable from a category page, an actor page, and the homepage's "Trending" row still canonicalizes only to `/watch/{slug}/`.
- **Origin normalization**: canonical always uses the single production origin (`https://phimtoico.org`, no `www`, consistent with the current server's HSTS configuration confirmed in the earlier server audit) — never a mixed scheme/host.

### 15.3 Slug changes and redirects

A dedicated table, owned by `tube-core`, backs redirect handling for every route type above (not just video):

```sql
CREATE TABLE wp_tube_url_redirects (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_path    VARCHAR(255) NOT NULL,
  target_path    VARCHAR(255) NOT NULL,
  redirect_type  ENUM('301','302') NOT NULL DEFAULT '301',
  created_at     DATETIME NOT NULL,
  UNIQUE KEY source_path_idx (source_path)
) ENGINE=InnoDB;
```

- When any slug changes (video, category, tag, actor, or studio), the event system (§6) fires a `*.slug_changed` event; a listener in `tube-core` inserts a row mapping the old path to the new one. This is automatic — an editor renaming a video never has to remember to add a redirect manually.
- Before a request would otherwise 404, `tube-core` checks `wp_tube_url_redirects` for a matching `source_path` and issues the stored redirect type. This lookup is on the request-serving hot path for any URL that doesn't resolve, so it's indexed (`source_path` is a unique key) and fronted by `tube-cache`'s object cache rather than hitting MySQL on every 404 candidate.
- **Deletion guardrail**: `tube-admin` blocks deleting an actor or studio that still has associated videos (per §14's relationship tables) rather than allowing silent orphaning — reassignment or explicit confirmation is required first, and only then does the deletion produce a redirect entry (to the general `/actor/` or `/studio/` browse page, since there's no single sensible target otherwise).
- **Legacy URLs from the current live site**: because this is a ground-up rebuild, any URLs that existed on the current `phimtoico.org` install are not automatically preserved. Generating a legacy-to-new redirect map (if needed) is a cutover-time task, scoped in the Operations Handbook (§18) rather than assumed here.

---

## 16. Cache invalidation strategy

Four layers exist in this architecture, and invalidation is handled differently for each. All purge logic is centralized in `tube-cache`, which is the only plugin ever allowed to call a cache-purge API — every other plugin only fires events (§6); it never purges anything itself.

| Layer | What it holds | Invalidation mechanism |
|---|---|---|
| Redis object cache | Computed values: video detail lookups, actor/studio lookups, listing query results | Explicit key purge, event-driven |
| `tube-cache` fragment cache | Rendered HTML fragments for anonymous grid/archive/single-video responses | Explicit fragment purge, event-driven |
| Cloudflare edge cache (CDN) | Full HTML responses for anonymous traffic | Purge-by-URL via Cloudflare's API, event-driven for page 1 / detail pages; TTL expiry for deep pagination |
| `wp_tube_search_index` | Denormalized listing/search data (not a "cache" in the purge sense, but has the same staleness concern) | Event-driven incremental upsert + nightly full rebuild (§2.6, unchanged) |

### 16.1 Exact purge behavior per event

| Event (§6) | Redis object cache | Fragment cache | Cloudflare edge | Search index |
|---|---|---|---|---|
| `video.published` | Purge video detail key; purge listing keys for every category/tag/actor/studio it belongs to | Purge `/watch/{slug}/`; purge **page 1 only** of each archive it now appears in, plus homepage "Recently Added" | Purge by URL: video URL + those same page-1 archive URLs | Insert row |
| `video.updated` (taxonomy/actor/studio changed) | Purge video detail key; purge **old and new** affected listing keys | Purge `/watch/{slug}/`; purge page 1 of old and new affected archives | Purge by URL: video URL + old/new page-1 archive URLs | Update row |
| `video.deleted` | Purge video detail key; purge affected listing keys | Purge `/watch/{slug}/` (now serves 410); purge page 1 of archives it was in | Purge by URL: same set | Delete row |
| `video.stream.status_changed` → `ready` | Purge video detail key | Purge `/watch/{slug}/` | Purge by URL: video URL | Update `duration_seconds` |
| `video.stats.rolled_up` (every 5 min, §7) | Purge "trending"/"most viewed" listing keys **only** — not every individual video's own cache entry | Purge homepage Trending/Most-Viewed fragments only | Purge by URL: homepage + trending/most-viewed archive URLs only | Update `views_total` (batched) |
| Actor/studio profile edited (bio, photo) | Purge that actor/studio's detail key | Purge `/actor/{slug}/` or `/studio/{slug}/` profile fragment | Purge by URL: that profile URL | No change (index doesn't store bio) |
| `*.slug_changed` | Purge old + new detail keys | Purge old + new fragments | Purge by URL: old (now a redirect) + new | Update denormalized slug fields if index stores them |

### 16.2 Deliberate non-purge policy

Archive pages beyond page 1 are **never** proactively purged — at 500,000+ videos, a single taxonomy edit could otherwise imply purging hundreds of deep pages. Deep pages are left to expire on a bounded TTL (recommended: 10–15 minutes at the edge) instead. This is an explicit scale tradeoff: page 1 (what nearly all users and crawlers actually hit) stays fresh instantly; deep pages are eventually consistent within one TTL window.

Because `video.stats.rolled_up` fires on a fixed 5-minute cron cadence (§7) rather than per individual view, trending/most-viewed data — and its cache purge frequency — is naturally bounded to once per rollup cycle. Trending is eventually consistent with a ~5-minute lag by design, which is the same tradeoff the write-path scalability in §2.2 already commits to; the cache policy here is just the read-side expression of that same decision.

---

## 17. Public API roadmap (future mobile app support)

The `tube/v1` REST surface (§9) was designed plugin-by-plugin with clean data ownership specifically so a future mobile app is an **additive extension**, not a backend re-architecture. This section reserves the additive surface needed for that, without building it now.

| Need | Additive change | Backend impact |
|---|---|---|
| Mobile authentication (watch history sync, future favorites/profile) | New `tube/v1/auth/*` routes, owned by `tube-core` (auth is foundational, not a new bounded concern — doesn't warrant a 7th plugin). Recommended mechanism: WordPress Application Passwords (core, built-in since 5.6) or a JWT layer on top | None — reads/writes the same `wp_tube_watch_history` table already designed |
| Efficient deep pagination for infinite-scroll mobile UIs | Optional `?cursor=` parameter on `/tube/v1/videos` alongside the existing `page`/`per_page` — cursor-based pagination avoids the `LIMIT 10000, 20`-style offset cost that degrades on a 500,000+-row table | None — same underlying `wp_tube_search_index` query, different pagination strategy |
| Smaller/differently-shaped mobile payloads | Optional `?fields=` sparse-fieldset parameter, response-shaping only | None — same query, response serialization only |
| Push notifications (e.g. "new video from an actor you follow") | Future: device-token registration endpoint + a listener on the existing `video.published` event (§6) | The event already exists; this is a new consumer of it, not a new data path |
| Per-client rate limiting for app/API-key traffic | Reuse `tube-cache`'s existing rate-limit helper (already built for the view-recording endpoint), scoped per API client instead of per-IP | None — same mechanism, different scope key |
| Breaking-change protection for long-lived app store releases | The existing `v1`-additive-only / `v2`-with-deprecation-window policy (§9) — mobile clients are the audience most exposed to this, since old app binaries stay installed far longer than a browser caches anything | No new policy — an existing one that matters more here |

**Conclusion**: nothing above requires touching the six-plugin boundary, the event system, or any existing table. Mobile support becomes: one new auth route set, two optional query parameters, and reuse of an existing rate limiter. This section exists to reserve those names/behaviors now so a future implementation doesn't collide with something the current build claims.

---

## 18. Operations handbook

### 18.1 Deployment

1. Every deploy goes to staging first, is verified, then promoted — no direct-to-production changes, consistent with this project's approach throughout.
2. Deploy sequence: pull the tagged release → `composer install --no-dev`, run separately inside each plugin's own directory (§4: no shared runtime autoloader — the repo-root `composer.json` is dev tooling only) → confirm CI's PHPCS gate already passed → `wp tube migrate status` to preview pending schema changes → `wp tube migrate up` → deploy code via an **atomic symlink swap** (release directory + a symlink flip to the current release) so a bad deploy can be reverted instantly by re-pointing the symlink, without a redeploy → smoke test (homepage loads, a video page loads, `GET /tube/v1/videos` responds) → purge only what actually changed (versioned asset filenames make most cache purging unnecessary) → watch error logs for the following 15 minutes.
3. Most deploys are zero-downtime by design (symlink swap is instant). A maintenance window is only needed for migrations that lock large tables or aren't backward-compatible with the previous code version — migrations should default to an **expand/contract** pattern (add new columns/tables first, migrate data, remove old ones in a later release) specifically to avoid needing downtime for the common case.

### 18.2 Backup

- Full daily database backup. Once the database is large (500,000+ video catalog plus statistics/search-index tables), prefer a physical/snapshot backup (e.g. volume snapshot, or a tool like XtraBackup) over a logical `mysqldump`, whose restore time becomes a real disaster-recovery liability at that size.
- Retention policy to be set explicitly before launch (e.g. 14 daily + 8 weekly + 6 monthly) — a number to confirm, not assumed here.
- Application code is not a backup target — git is the source of truth. Custom poster overrides live in Cloudflare Images (§8), not on the origin server, so the database backup (which holds the reference IDs) plus Cloudflare's own durability covers that content; there's no local media directory to separately back up by design.
- Backups are stored off the origin server (object storage / separate region) — this directly fixes a gap found in the original site audit, where the backup plugin was installed but its backup directory was empty and no cron existed to run it.
- Backups are periodically restore-tested (recommended monthly) to a scratch environment. An unverified backup is not a backup.

### 18.3 Rollback

- **Code rollback**: symlink swap back to the previous release directory. Near-instant, requires keeping the last several releases on disk (recommended: last 5).
- **Schema rollback**: `wp tube migrate down --plugin=<slug> --to=<version>` (§3). Only ever safe because §3 makes a working `down()` mandatory for every migration, not optional — a migration without a verified rollback does not merge.
- **Rollback ordering is always code-then-schema, never the reverse** — roll the application back to code that doesn't depend on the new schema before reverting the schema itself, or rely on the expand/contract pattern to keep the old code compatible with the new schema long enough to roll back safely.
- **Data rollback** (restoring from backup) is a disaster-recovery action, not a routine rollback step — see §18.5.

### 18.4 Migration procedure

- Migrations run only as part of a deploy, never ad hoc against production outside a deploy window.
- Every migration's `down()` is specifically reviewed for correctness before merge, not just its `up()`.
- Migrations touching the largest/partitioned tables (`wp_tube_video_views`, `wp_tube_search_index`, `wp_tube_video_metadata` at 500,000+ rows) are dry-run against a production-scale (or realistically-sized synthetic) staging copy first — `ALTER TABLE` locking/duration behavior at that size differs meaningfully from a small staging database, which is why staging needs periodic refreshes from production-scale data specifically to catch this class of problem before it reaches production.

### 18.5 Monitoring

- **Application**: PHP error/fatal log monitoring; REST API error rate and latency per route (the view-recording endpoint especially, as the highest-frequency route); success/failure of every Linux-cron-invoked WP-CLI job from §7 (job failures are now purely a Linux-cron concern since WP-Cron is disabled — each job's exit code should be logged and alerted on, including a "has `stats:rollup` actually run in the last N minutes" dead-man's-switch check).
- **Infrastructure**: disk usage, PHP-FPM worker saturation, MySQL slow-query log (any query against a 500,000+-row table that isn't hitting an index should alert, not just get noticed eventually), Redis memory usage and eviction rate — Redis is now load-bearing for the view-buffering write path (§2.2/§7), so Redis pressure has a direct data-loss implication, not just a cache-miss/performance one.
- **Business/content**: `import_queue` depth and failure rate (a stuck or growing backlog is itself alertable); time since the last successful `index:rebuild` (search staleness).
- Specific alert thresholds are a Phase 11/12 task, set from real staging load-test data rather than guessed here.

### 18.6 Disaster recovery

- RPO/RTO targets are a business decision to make explicitly before launch, not assumed by this document — e.g. "RPO: 24h of view/import data" and "RTO: 4h to restore service" are placeholders for the actual numbers to be agreed on.
- Documented, drilled scenarios:
  - **Database loss/corruption**: restore from the most recent verified backup; any view counts buffered in Redis but not yet flushed (§7's per-minute flush cadence bounds this to at most a few minutes of data) are an accepted, bounded loss, not something the restore process needs to reconstruct.
  - **Full server loss**: provision a new server, restore code from git, restore the database from the off-server backup, reconfigure DNS/Cloudflare if the IP changed.
  - **Cloudflare Stream/Images account issue**: because video and (override) image bytes live entirely outside the WordPress server by design (§8 and throughout), a WordPress-server-level disaster never loses the actual video/image content — only the catalog metadata referencing it. This is called out explicitly as a direct, deliberate payoff of the "store IDs, never files" principle applied everywhere in this architecture.
  - **Redis loss**: acceptable, bounded loss (at most the unflushed portion of the last flush interval) — treated as a known tradeoff, not a DR event requiring restoration.
- At least one full restore-to-scratch-environment drill is required before go-live, and periodically afterward — this is the same drill as the backup-verification step in §18.2, just framed as a DR exercise rather than a backup check.

---

## 19. Revision 5: post-approval optimization pass

Made after Phases 0–2 were implemented and committed, in response to an explicit instruction to re-challenge the whole architecture — not just check it for consistency with itself — from the standpoint of building the fastest, cleanest, most maintainable large-scale WordPress application achievable. Full reasoning for every item below is in `ARCHITECTURE-OPTIMIZATION-REVIEW.md`; each is also logged in `ARCHITECTURE-CHANGELOG.md`. None of it invalidates Phases 0–2 (confirmed in that review's Migration Impact Report) — these are decisions for code not yet written.

**§19.1 — Interface justification rule.** An interface is created only when it has a realistic second implementation — either a genuine competing implementation, or a test fake that will actually be built and used to unit-test real logic without a live WordPress/database/external-service dependency, the same pattern already proven by `HookBusInterface`/`WordPressHookBus`/`RecordingHookBus` and `SchemaVersionRepositoryInterface`/`SchemaVersionStore`/`InMemorySchemaVersionRepository`. "We might swap the vendor/library someday" is not sufficient on its own. This rule already existed implicitly (`DEVELOPMENT_RULES.md` §6.6); §19.4–§19.6 below are it applied to specific upcoming decisions.

**§19.2 — Rejected: generic service container.** Considered, and explicitly not adopted. `Plugin.php` keeps hand-written, typed, lazy-singleton accessor methods (as `migration_runner()` and `events()` already are) rather than routing through a string- or class-keyed container. Reconsider only if a single plugin's bootstrap class exceeds roughly 6–8 such accessors, or starts containing real logic beyond construction/wiring — not before.

**§19.3 — Database access consolidation.** `AbstractMigration` and `SchemaVersionStore` (and every future repository) share one `db(): wpdb` accessor instead of each independently calling `global $wpdb;` — fixes a confirmed, current duplication (5 independent occurrences in `SchemaVersionStore` alone). This is not read/write-replica infrastructure; §10's "once traffic requires it" framing for replicas is unchanged. Not yet implemented in code — applies from the next commit that touches a repository.

**§19.4 — Repository convention.** A data-access class for a dedicated table is named `{Noun}Repository`, follows `SchemaVersionStore`'s shape. It gets a paired `{Noun}RepositoryInterface` only when §19.1's bar is cleared — not automatically for every table.

**§19.5 — Cache and video-provider abstractions, re-justified.** `tube-cache`'s `CacheInterface` (Phase 3) and `tube-player`'s `VideoProviderInterface` (Phase 6) are both adopted, but on the basis of §19.1 (a test fake each plugin's dependents genuinely need), not vendor-swap speculation — that remains a real but secondary benefit.

**§19.6 — Search backend, settled.** MySQL `FULLTEXT` + indexed taxonomy filtering is the committed first implementation for `tube-search` (§2.6, Phase 7) — no standing up Elasticsearch/OpenSearch before real query-pattern data justifies it. Whether the query layer sits behind an interface is left to Phase 7, under §19.1, not pre-decided here.

**§19.7 — "Future microservice compatibility" clarified.** Means clean plugin boundaries (own data, communicate via documented APIs/events, no direct cross-plugin table access) that make extracting one concern easier *if ever needed* — not a mandate to build literal service/network boundaries now. This project is a WordPress plugin suite; treat it as one.

**§19.8 — Bulk relationship-table writes.** Any code writing multiple rows to a relationship table (`wp_tube_video_actors`, `wp_tube_video_studios`, or any future equivalent) in response to one save uses a single multi-row `INSERT`, never a loop of single-row inserts. Applies starting Phase 7, when this code is first written — written down now so the naive loop is never the first draft.

**§19.9 — Testing-architecture checkpoint.** Phase 1's decision to defer a full `WP_UnitTestCase` integration suite remains correct for now, but is no longer open-ended: it must be explicitly reconsidered before Phase 5 (import pipeline) or Phase 6 (`tube-player`), whichever comes first.

---

Phases 0–2 are implemented and committed. Revision 5 (§19) is approved and in effect. **The architecture is now frozen** — see `ARCHITECTURE_FREEZE.md` for the full frozen/flexible/deferred classification and `DEVELOPMENT_RULES.md` §8 for the change-control process any further architecture change must follow. Further implementation continues phase by phase, per `DEVELOPMENT_RULES.md` — waiting for explicit approval before any new production code is written.
