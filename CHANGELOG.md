# Changelog

All notable changes to this project. Format loosely follows [Keep a Changelog](https://keepachangelog.com/); this project did not tag intermediate releases during development (every plugin/theme carried `0.1.0` internally through Phase 11), so **1.0.0 is the first tagged release** and this entry summarizes everything that went into it, phase by phase. Future releases will get their own dated entry above this one.

## [1.1.0] — 2026-08-07

Phase 13: Production UI — a full visual redesign of `tube-theme` (dark theme, hero banner, header mega menu, video-card hover effects, infinite scroll, actor/studio pages, a modern search page), hand-written CSS/JS with no page builder, no CSS framework, and no build tooling. User-commissioned post-1.0.0 phase (`PHASE-13.md`, `ARCHITECTURE-CHANGELOG.md`). Only `tube-core`, `tube-player`, and `tube-theme` changed this release — `tube-cache`/`tube-search`/`tube-seo`/`tube-admin` are unaffected and stay at `1.0.1`.

### Added

- **Theme** — dark-only design system (`#0b0e14` background, `#ff3d5a` accent); sticky header with a "Categories" mega menu (categories + "Browse by Studio") and a mobile off-canvas nav; a homepage hero banner auto-populated from the current #1 trending video; video cards with a hover zoom/play overlay, a duration badge, and a "starring" actor/studio badge; infinite scroll on category/tag/actor/studio archives and search results (progressive enhancement over real paginated URLs, per `ARCHITECTURE.md` §15.2's already-specified design — never a distinct AJAX-only page state); actor/studio profile pages (photo, name, bio) and new paginated actor/studio directory pages (`page-templates/actors.php`/`studios.php`); a redesigned search page; loading/empty/error states throughout (a shared `.empty-state` component, an infinite-scroll loading/error status region with `aria-live="polite"`); a multi-column footer.
- **`tube-core`** — `ActorRepository::find_many()`/`StudioRepository::find_many()` (batched-query + request-lifetime-cache, same pattern as `VideoMetadataRepository::find_many()`) and 6 new template tags: `tube_core_list_actors()`/`_count_actors()`/`_list_studios()`/`_count_studios()`/`_get_actors()`/`_get_studios()`.
- **`tube-player`** — `ImageSize::Avatar` (a new square size preset — requires configuring a matching `avatar` variant in the Cloudflare Images dashboard before this ships in production), a new `ProfileImageHtmlRenderer` class, and `tube_player_get_profile_image_html()`.

### Verified

- `phpcs` exit `0` and `phpstan analyse` (level `max`) `[OK] No errors`, whole repository.
- 166 unit tests / 92 integration tests passing (up from 1.0.1's 165/84) across every plugin that has a suite.
- Live SQL-query-count investigation found and fixed two real inefficiencies in the new header/footer/mega-menu code (a duplicate `get_terms()` call; 5 separate page-template-URL lookups collapsed into 1 bulk query) before they shipped — full accounting in `BENCHMARKS.md`'s Phase 13 section. No regression in any benchmarked metric.
- Full benchmark suite re-run and compared against 1.0.1's baseline — no regression.

### Known limitations

- No real-browser interaction/visual QA was performed this release (no browser-automation tool available in the implementing session) — everything verifiable without one was verified (JS syntax, CSS validity, every page's live HTML/markup/SQL cost via `curl`). A manual pass in a real browser (mega menu, mobile nav, infinite scroll, responsive breakpoints) is recommended before this reaches production traffic. Full detail in `PHASE-13.md` §10.
- The Cloudflare Images `avatar` variant (and, pre-existing, `grid_card`/`hero`/`og_image`) are not yet configured in any environment available this release — the code degrades gracefully (no photo renders) until they are.

## [1.0.1] — 2026-08-07

Deployment-procedure hotfix. No application code changed — every plugin's runtime behavior is identical to 1.0.0.

### Fixed

- **Release-blocking**: `docs/DEPLOYMENT.md` §3 step 2 documented a single `composer install --no-dev` run against the release root, but this monorepo has no shared runtime autoloader — the root `composer.json` is dev-tooling-only (PHPCS/PHPStan) and has no `autoload` section. Each of the 6 plugins is independently `composer install`-able via its own `composer.json` (`ARCHITECTURE.md` §4), and every plugin's bootstrap file only conditionally requires its *own* `vendor/autoload.php` (guarded by `file_exists()`, no fallback). Following the documented single-root-install sequence against a genuinely clean checkout left every plugin's `vendor/` directory missing, so every plugin's namespace was never autoloaded and WordPress fataled on `plugins_loaded` with `Class "Tube_X\Plugin" not found` — found deploying the tagged `v1.0.0` release to a clean production VPS.
  - Root cause this passed 12 phases of review undetected: every local/staging verification ran against a long-lived Docker checkout whose plugin `vendor/` directories were generated once, early in development, and never deleted — so `plugins_loaded` always succeeded locally regardless of whether the documented deploy procedure itself was correct. No phase's verification ever exercised a genuinely clean checkout end-to-end.
  - Fixed: `docs/DEPLOYMENT.md` §3 step 2 now runs `composer install --no-dev --optimize-autoloader` separately inside each of the 6 plugin directories (all `composer.lock` files are git-tracked, so this is a reproducible `install`, never an `update`). `docs/UPGRADE.md` and `ARCHITECTURE.md` §18.1 updated for consistency. Added a "clean-checkout boot test" item to `docs/DEPLOYMENT.md`'s pre-deployment gate specifically to catch this class of bug before it reaches production again.
  - Verified: reproduced the fatal by removing `tube-cache`'s `vendor/` directory against the live staging stack (`Tube_Cache\Plugin` class missing), then confirmed the fixed per-plugin `composer install --no-dev` sequence restores it (`class_exists('Tube_Cache\Plugin')` true, homepage `200`).
- Vendor directories were correctly `.gitignore`d all along (`/**/vendor/`, standard Composer practice — dependency source is never committed, only `composer.lock`) and were **not** accidentally omitted from the release; the defect was purely in the documented deploy procedure not generating them on the production server.

## [1.0.0] — 2026-08-06

First production release. Six independent WordPress plugins (`tube-core`, `tube-cache`, `tube-player`, `tube-search`, `tube-seo`, `tube-admin`) plus a presentation-only theme (`tube-theme`), built against a frozen architecture (`ARCHITECTURE.md`, `ARCHITECTURE_FREEZE.md`) for a confirmed production target of a single VPS, 3,000–10,000 videos, a few million pageviews/month, Redis, MySQL, and Cloudflare CDN.

### Added

- **Foundation** (`tube-core`) — `video` custom post type; `video_category`/`video_tag` native taxonomies; dedicated `actor`/`studio` tables (not taxonomies, `ARCHITECTURE.md` §14); the schema migration framework (`wp_tube_schema_versions`, reversible `up()`/`down()` per migration); `wp_tube_video_metadata` (Cloudflare Stream UID, encoding status, duration, poster/OG image overrides); the cross-plugin event dispatcher (`Dispatcher`/`EventCatalog`/`HookBusInterface`).
- **Caching** (`tube-cache`) — Redis-backed `CacheInterface`, fail-open on any Redis failure (connection *or* server-side, e.g. an `OOM` response); a rate-limiting primitive (`RateLimiter`); an event-driven cache-purge subscriber covering every documented purge case in `ARCHITECTURE.md` §16.1, including video publish/update/delete, stats rollup, and stream-status changes.
- **Views and statistics** (`tube-core`) — hourly-bucketed `wp_tube_video_views`, Redis-buffered write path (`RedisViewCounter`) with a per-minute flush cron job, pre-aggregated `wp_tube_video_statistics` (total/today/7d/30d), retention/partition-maintenance cron.
- **Import pipeline** (`tube-core`) — durable `wp_tube_import_queue` table + WP-CLI batch worker (`import:enqueue`/`import:process`/`import:status`), a Cloudflare Stream webhook handler with HMAC signature verification and replay protection, `wp_tube_watch_history` (guest + logged-in viewer progress) with its own public, self-scoped REST endpoint (`POST /tube/v1/videos/{id}/watch-history`).
- **Playback** (`tube-player`) — Cloudflare Stream URL construction from a stored UID, click-to-load embed markup, image/thumbnail rendering with Cloudflare Images override support.
- **Search and discovery** (`tube-search`) — denormalized `wp_tube_search_index` (MySQL FULLTEXT), event-driven incremental sync plus a full `index:rebuild` WP-CLI command, related/trending/most-viewed/latest discovery queries, full-text search with relevance ranking.
- **Presentation** (`tube-theme`) — the full public-facing template layer (homepage, archive, search, latest/most-viewed/trending, single video, related videos) against the plugins' documented template-tag APIs only — no direct database access, no business logic in the theme.
- **SEO** (`tube-seo`) — title/meta description/canonical/robots/OpenGraph/Twitter Cards/JSON-LD (`VideoObject`/`BreadcrumbList`/`CollectionPage`) structured data, pagination metadata, and cron-driven video XML sitemap generation (`sitemap:generate`, sharded per Google's sitemap size limits) served at clean URLs.
- **Operational UI** (`tube-admin`) — import dashboard, statistics dashboard (sortable by every `views_*` window), video metadata editor with custom poster/OG-image upload (via Cloudflare Images), actor/studio assignment (including bulk tools), a system status page, and a settings screen — every write action gated by `current_user_can()` + a nonce.
- **Documentation** — `ARCHITECTURE.md`, `ARCHITECTURE_FREEZE.md`, `DEVELOPMENT_RULES.md`, per-phase `PHASE-X.md` evidence reports (0 through 12), `BENCHMARKS.md`, and this release's production runbooks: `docs/DEPLOYMENT.md`, `docs/BACKUP_RESTORE.md`, `docs/UPGRADE.md`, `docs/ROLLBACK.md`, `docs/MONITORING.md`, `RELEASE.md`.

### Fixed (during hardening, before this release)

- A theme grid N+1 query pattern (one `VideoMetadataRepository::find()` call per card, unbatched, on every homepage/archive/search/related-videos render) — fixed with a request-lifetime cache and batch-priming helper (Phase 11).
- `RedisCache`/`RedisViewCounter` catching only `Predis\Connection\ConnectionException`, missing Redis server-side errors (e.g. an `OOM` rejection under memory pressure) — widened to the shared `Predis\PredisException` base (Phase 11).
- A missing cache-purge subscriber for the `tube_core.video.stream_status_changed` event, live since Phase 5 with no listener (Phase 11).
- A missing index (`views_today_idx`/`views_30d_idx` on `wp_tube_video_statistics`) for a sort option the Statistics dashboard had already shipped (Phase 11).
- The nightly `index:rebuild` cron entry, which was still a no-op placeholder instead of the real command (Phase 11).
- A cache-invalidation gap in the theme grid N+1 fix itself: the new `VideoMetadataRepository` cache had no invalidation on its own write methods, so a `find()` cached before a `create()`/`update_*()` could shadow the write within the same request — caught by the Phase 11 Implementation Review's own test-suite run, fixed with invalidation on every write path plus two regression tests.

### Security

- Every `$wpdb` query across all 6 plugins verified parameterized (`prepare()`), no `SELECT *`, no unbounded result set without a documented, scale-justified reason.
- Every `wp-admin` write action verified gated by both `current_user_can()` and a nonce (`check_admin_referer()`).
- Both `/tube/v1` REST routes verified: the Cloudflare Stream webhook uses constant-time HMAC signature verification with replay protection; the public watch-history endpoint is deliberately unauthenticated by design (writes only the caller's own progress, never reads or exposes another viewer's data) and validates every input against a sane bound.
- All secrets sourced from environment/`wp-config.php` constants, never hardcoded; `.gitignore` excludes `.env*`.

### Verified

- Zero undocumented technical debt.
- `phpcs` exit `0` and `phpstan analyse` (level `max`) `[OK] No errors`, whole repository.
- 165 unit tests / 84 integration tests passing across all plugins that have suites.
- Every schema migration (10 total, across `tube-core` and `tube-search`) round-trip tested — `down()` genuinely, structurally reverses `up()` — including a full drill rolling `tube-core`'s entire schema down to its floor and back up against a full clone of real staging data, with unrelated tables' data completely undisturbed throughout.
- Full benchmark suite re-run and compared against every prior phase's baseline — no regression at any point in the project's history.

See `PHASE-0.md` through `PHASE-12.md` for the complete, evidence-backed history of every phase, and `RELEASE.md` for this release's summary.
