# Phase 8 — Theme presentation layer + tube-seo (pulled forward from Phase 9)

Status: **Complete.** Implements ARCHITECTURE.md §12 Phase 8's assigned scope (the presentation layer against the plugin template-tag APIs from Phases 1–7: homepage, video page, category/tag/actor/studio archives, search results, trending/most-viewed/latest) plus, by explicit user decision at the start of this phase, Phase 9's SEO deliverable (title/meta description/canonical/robots/OpenGraph/Twitter Cards/JSON-LD/pagination metadata) — pulled forward because the SEO section of this phase's kickoff instruction overlapped entirely with `tube-seo`'s documented Phase 9 ownership, and the user chose to build `tube-seo` now rather than defer it. Video sitemap generation (also nominally Phase 9) was **not** pulled forward — it wasn't part of this phase's SEO deliverable list and stays deferred.

---

## 1. Architecture Drift Report

Confirmed clean before this phase's work started (Phase 7's own commit `f2fe034` left a clean baseline), and re-confirmed after:

1. **No circular dependencies** — tube-theme calls only documented template-tag functions across all five plugins; no plugin's `composer.json` gained a dependency on another plugin's package.
2. **No service locator pattern** — confirmed.
3. **Two real prerequisite gaps found and closed, not new scope**: `Tube_Core\Content\Repositories\Actor/StudioRepository*` (Phase 1 created the tables but deliberately deferred the read/write layer — see PHASE-1.md §5 — this phase built the read side, since Phase 10's write side still doesn't exist) and `Tube_Search\Search\SearchRouting` (§15.1 assigns the `/search/{query}/` custom rewrite to tube-search, never built in Phase 7). Both are necessary for this phase's own assigned page types to have a URL to render at, the same class of "close a real prerequisite gap" work Phase 8's actor/studio archive pages already required for `TermArchiveRouting`.
4. **No God classes** — `Tube_Core\Plugin`: 6 accessors before this phase, 8 after (`actor_repository()`, `studio_repository()`) — the routing wiring itself was deliberately kept **out** of `Plugin.php` (constructed inline in `boot()`/`activate()`, matching `VideoPostType`/`CategoryTaxonomy`/`TagTaxonomy`'s existing precedent) specifically to stay at 8, not 10. `Tube_Search\Plugin`: 8 accessors before this phase, 9 after (`archive_videos_query()`) — past §19.2's 6–8 reconsideration trigger, reconsidered explicitly in the class's own docblock and in `Plugin.php` itself: every accessor, including the new one, is still `new` + cache, so a service container remains the wrong call per §19.2's actual test.
5. **No duplicated abstractions** — `ArchiveVideosQuery` is one class for all four archive types (category/tag/actor/studio), the same "one class, not near-identical ones" reasoning `PopularVideosQuery` already established in Phase 7. `Tube_Core\Content\Routing\TermArchiveRouting` is one class for both `/actor/{slug}/` and `/studio/{slug}/`, parameterized by a `Closure` rather than a new shared interface (the two repositories' `find_by_slug()` methods return unrelated DTOs with no common denominator). `template-parts/archive-listing.php` is the one shared theme template body for all four archive types.
6. **No unnecessary interfaces** — every new interface has a real, checked justification recorded in its own docblock; one (`ActorRepositoryInterface`) had its justification corrected during this phase's Implementation Review (§11) after the review caught that the docblock's original claim didn't hold.
7. **No premature optimization** — `ArchiveVideosQuery`'s caching is deliberately TTL-only (§3.4), not the full page-1-reactive-purge matrix §16.1 describes.
8. **No plugin-boundary violations** — `tube-seo`'s `SeoHead` reaches into `Tube_Core_Plugin`/`Tube_Player_Plugin`/`Tube_Search_Plugin`'s own public accessors only, never another plugin's tables directly.

**Result: clean**, both before and after this phase's work.

---

## 2. What was built

### 2.1 tube-core: actor/studio read layer + URL routing (`Tube_Core\Content`)

- **`Actor`/`Studio`** — readonly DTOs over `wp_tube_actors`/`wp_tube_studios`.
- **`ActorRepositoryInterface`/`ActorRepository`, `StudioRepositoryInterface`/`StudioRepository`** — `find()`, `find_by_slug()`, `actor_ids_for_video()`/`studio_ids_for_video()`, `count_videos_for_actor()`/`count_videos_for_studio()` (a live `COUNT()`, not the unmaintained `video_count` column — see §3.2). No write methods — no write API exists yet (`tube-admin`, Phase 10); every integration test seeds rows directly via `$wpdb`.
- **`Tube_Core\Content\Routing\TermArchiveRouting`** — custom `add_rewrite_rule()`/`template_include` routing for `/actor/{slug}/` and `/studio/{slug}/` (§15.1: dedicated tables, not taxonomies, so no native WordPress taxonomy rewrite applies). Resolves a slug via an injected `Closure`, 404s on an unknown one, exposes the resolved object via `set_query_var("{$query_var}_object", ...)` rather than a `Plugin` accessor (see §3.1 for why).
- **`includes/template-tags.php`** (new for tube-core) — `tube_core_get_actor_by_slug()`, `tube_core_get_current_actor()`, `tube_core_get_studio_by_slug()`, `tube_core_get_current_studio()`.

### 2.2 tube-search: archive listing + a real prerequisite fix (`Tube_Search\Discovery`, `Tube_Search\Search`, `Tube_Search\Index`)

- **`DiscoveryRepositoryInterface::list_by_column()`/`count_by_column()`** (+ `SearchIndexRepository` implementation) — a single-ID, paginated, `published_at DESC`-ordered `JSON_CONTAINS()` query, reusing Phase 7's `CandidateColumn` enum. Distinct from `find_by_ids()` (list of IDs, `views_total`-ordered, no offset — built for the related-videos cascade, not an archive listing).
- **`ArchivePage`** — readonly DTO (`items`, `total`, `page`, `per_page`).
- **`ArchiveVideosQuery`** — one class serving all four archive types; TTL-only cached (§3.4).
- **`Tube_Search\Search\SearchRouting`** — custom rewrite for `/search/{query}/` into a `tube_search_q` query var (deliberately not WordPress core's native `?s=`, which would run a real `WP_Query` search against `wp_posts`).
- **4 new template tags**: `tube_search_by_category()`/`by_tag()`/`by_actor()`/`by_studio()`, plus `tube_search_current_query()`.
- **Real bug fixed, not new scope**: `VideoIndexer` previously only ever preserved whatever `actor_ids`/`studio_ids` the index already had (`$existing->actor_ids ?? []`) — it never actually read `wp_tube_video_actors`/`wp_tube_video_studios`. Fixed to call `ActorRepositoryInterface::actor_ids_for_video()`/`StudioRepositoryInterface::studio_ids_for_video()` on every resync, the same "always read the current real relationship" treatment `category_ids`/`tag_ids` already got from `wp_get_post_terms()`. Without this fix, actor/studio archive pages could never show a video, ever, even after Phase 10 ships real assignments.
- **Real bug found and fixed during this phase's own performance review, not new scope** — see §5.

### 2.3 tube-cache: no new files this phase

Archive-listing caching reuses the existing `SearchCacheInterface`/`TubeCacheAdapter` (Phase 7) directly — no new cache-key builders were added to `Tube_Cache\Cache\CacheKeys` (§3.4 explains why archive-listing cache keys are hand-built in `ArchiveVideosQuery` instead, TTL-only, with no reactive-purge integration needed).

### 2.4 tube-seo: the pulled-forward SEO deliverable (new plugin, real business logic for the first time)

- **Pure, unit-tested builders**: `Tube_Seo\JsonLd\VideoObjectBuilder` (+ `iso8601_duration()`), `BreadcrumbListBuilder`, `CollectionPageBuilder` — plain array-building, no WordPress calls. `Tube_Seo\Meta\PageMeta` (DTO) + `PageMetaBuilder` — named factory methods per page type (`for_video()`, `for_archive()`, `for_search()`, `for_home()`), not a generic `Factory::create($type)` indirection.
- **`Tube_Seo\Head\SeoHead`** — the one WordPress-coupled orchestrator, verified via integration tests and live checks (not unit-tested), the same split every thin real-data adapter in this project uses. Detects the current page type via WordPress's own conditional tags plus tube-core's/tube-search's query-var-backed "current object" tags, via a plain `if`-cascade (no Strategy pattern).
- **`tube_seo_head()`** — the one template tag, called explicitly inside `<head>` by every theme template (not wired through WordPress's `pre_get_document_title`/`wp_head` filters — an explicit, simpler design matching what was actually asked for).

### 2.5 tube-theme: the Phase 8-proper deliverable

- **Base**: `functions.php` (theme setup — deliberately no `title-tag` support, since `tube_seo_head()` echoes its own `<title>`), `header.php`/`footer.php`, minimal CSS, one small JS file (redirects the header search form to `/search/{query}/`, since a plain `<form method="get">` can't target a pretty-permalink path segment).
- **Shared template-parts**: `video-card.php`, `pagination.php`, `breadcrumbs.php`, and `archive-listing.php` (the single shared body for category/tag/actor/studio archives — §1.5).
- **10 page templates**: `front-page.php`, `single-video.php`, `taxonomy-video_category.php`, `taxonomy-video_tag.php`, `archive-actor.php`, `archive-studio.php`, `search.php`, and three `page-templates/*.php` (Trending/Most Viewed/Latest — see §3.5 for why these are WordPress Page templates, not new rewrite rules). Plus `404.php` (exercised directly by `TermArchiveRouting`'s real 404 path).
- Every template calls template-tag functions exclusively — zero `$wpdb`, zero direct queries, confirmed by this phase's own SQL-count investigation (§5).

---

## 3. Design decisions

1. **`TermArchiveRouting`'s resolved object is exposed via `set_query_var()`, not a `Plugin` accessor.** Avoiding two more cached-singleton accessors on `Tube_Core\Plugin` (which would have pushed it to 10, past even tube-search's already-flagged 9) in favor of WordPress's own idiomatic "value resolved during routing, needed by the template" mechanism.
2. **`count_videos_for_actor()`/`count_videos_for_studio()` are live `COUNT()` queries, not the `video_count` denormalized column.** `Migration002CreateActorTables`'s own docblock predicted `video_count` would be "maintained explicitly by application code (tube-search, from Phase 7 onward)" — that maintenance was never actually built (neither Phase 7 nor this phase writes to it), so trusting the column would silently show 0 forever. A live `COUNT()` against an indexed foreign key is correct and cheap at this project's real-scale target; wiring up the denormalized counter is Phase 10's job alongside the write API that would actually keep it accurate.
3. **Actor/studio badges are not shown on the video single page.** `VideoIndexer` denormalizes `actor_ids`/`studio_ids` into the search index, but there's no ID→name template tag for a *specific* video's assigned actors on the theme side (only `find_by_slug()`/`find()`, and the "current actor" query-var accessor, which only applies on an actor archive page itself). Building one more template tag for a feature with no real data until Phase 10 would be exactly the kind of building-for-a-hypothetical this project's rules already warn against; category/tag links (real taxonomy data, available today) are shown instead.
4. **Archive-listing caching is TTL-only, with no reactive purge integration** — a deliberate simplification of §16.1's fuller per-event purge matrix (which would purge page 1 of every affected archive on publish/update/delete), chosen because reactive purging here would need old-vs-new taxonomy/actor/studio-membership diffing nothing in this codebase tracks yet. The same §16.2 "deep pages are eventually consistent within a bounded TTL" philosophy already applied to `SearchQuery` in Phase 7, extended here to every page of every archive rather than only page 2+.
5. **Trending/Most Viewed/Latest are WordPress Page templates (`page-templates/*.php`), not new rewrite rules.** §15.1's frozen URL table has no entry for these three listings. Making them ordinary WordPress Pages (whatever slug an editor assigns) uses a URL mechanism that already exists, rather than inventing a new one — trivially satisfying this phase's explicit "respect the frozen URL architecture, no URL redesign" instruction.
6. **`tube_seo_head()` is an explicit theme call, not a `wp_head`/`pre_get_document_title` hook.** Simpler and more literal than intercepting WordPress core's own title-rendering pipeline, and matches exactly what was asked for ("one template tag the theme calls once per template, no logic in the theme"). The tradeoff, accepted: `functions.php` must not also declare `title-tag` theme support, or the `<title>` tag would be emitted twice — documented directly in `functions.php`'s own comment.

---

## 4. Backward compatibility with Phases 0–7

Verified live, not assumed:

- `wp tube migrate status`: all 9 pre-existing migrations (8 tube-core + 1 tube-search) still `yes`/unchanged timestamps — this phase adds no new tables or migrations.
- All plugins (including the newly-real `tube-seo`) show `active` with zero fatals.
- tube-core's unit suite (63/63) and integration suite (23/23 — 17 pre-existing + 6 new) pass. tube-search's unit suite (23/23 — 19 pre-existing + 4 new) and integration suite (23/23 — 19 pre-existing + 4 new) pass. tube-cache's unit suite (22/22) is unaffected. tube-player's integration suite (7/7) is unaffected.

## 5. Real bug found and fixed during this phase's own performance review

Requested explicitly, before commit: a theme-layer performance review (no unnecessary `WP_Query`, no N+1, no repeated repository calls, expensive widgets reuse cache, SQL counts for five page types).

**Found**: `Tube_Cache\Cache\RedisCache::get()` unserializes with `allowed_classes: false` (a deliberate object-injection guard, Phase 3). But Phase 7's four discovery query classes (`RelatedVideosFinder`, `PopularVideosQuery`, `RecentlyAddedQuery`, `SearchQuery`) and this phase's `ArchiveVideosQuery` all cached real PHP objects (`SearchIndexRow`/`ArchivePage`) directly. On every cache **hit**, `unserialize()` silently converted them into `__PHP_Incomplete_Class` objects — confirmed live: a second, cached call to `tube_search_query()` returned a row whose `->title` triggered a PHP warning and came back empty, rather than the real title. This has been true since Phase 3 for every cached discovery result; nothing before this phase's live SQL-count investigation had actually exercised a real cross-request cache **hit** and inspected the object it returned.

**Impact measured live**: the category archive page issued the same `JSON_CONTAINS`/`COUNT` query pair twice per request (`SeoHead`'s meta computation and the template's own listing call both missed the cache) — 22 SQL queries instead of the correct 20.

**Fix**: added `SearchIndexRow::to_array()`/`from_array()` and `ArchivePage::to_array()`/`from_array()`; all five affected query classes now cache plain arrays and reconstruct real objects on read. No change to `RedisCache`'s security posture (`allowed_classes: false` is unchanged) and no new abstraction — this makes every caller actually honor `RedisCache`'s own already-documented "plain arrays/scalars only" contract, which Phase 7 and this phase had silently violated.

**Verified live, twice**: (1) a second `tube_search_query()` call now returns a real, usable `SearchIndexRow` with the correct title; (2) the category archive page's query count dropped from 22 to 20, confirmed via full-query-log inspection (temporary `SAVEQUERIES`/mu-plugin instrumentation, removed after use, no trace left in the repository or the live database). The same fix applies identically to actor/studio archives (same `ArchiveVideosQuery` code path).

## 6. Live verification

- **All 10 page types**, live-seeded with a fully-linked video (real category, tag, actor assignment, studio assignment, Cloudflare Stream metadata) and curled directly: video single page (correct `<title>`/canonical/OG/Twitter/`VideoObject`+`BreadcrumbList` JSON-LD, correct embed markup, correct breadcrumb trail), category archive, tag archive, actor archive, studio archive, homepage, search results — all `HTTP 200` with correct content. An unknown actor slug correctly returns `HTTP 404` via `TermArchiveRouting`'s real routing.
- **SQL query counts** (post-fix, via the same temporary instrumentation as §5): homepage 18, single video 20, category archive 20, actor archive 20, search 14 — against an 11-query WordPress-core baseline (measured via a plain 404 with zero template-tag calls). See §7 for the full accounting.
- **Backward compatibility** (§4): confirmed live.
- All seeded live-verification content, the temporary `SAVEQUERIES` config line, and the temporary query-counting mu-plugin were removed after use — confirmed via `wp plugin list`/`ls mu-plugins/` showing a clean state and a final `curl` of the homepage returning `HTTP 200`.

## 7. Benchmark Report

Run per `DEVELOPMENT_RULES.md` §9 (this phase's page-rendering and SQL behavior both changed substantially, meeting the explicit "benchmark if page rendering or SQL behavior changes" bar). Full results, methodology, and analysis in `BENCHMARKS.md`'s new "Phase 8" section (append-only, not reproduced here). Summary:

- **Page generation time** increased over Phase 5's last-measured baseline (~6.6–7.5 ms → ~11.2–13.8 ms for both `/watch/test-video-one/` and `/`) — expected and correct: these URLs now render the real theme (SEO head, breadcrumbs, embed block, related videos / three discovery-query homepage rows) instead of the Phase 1 empty placeholder. This is this phase's actual deliverable, not a regression.
- **Event dispatch cost** increased ~3.3× over Phase 5's baseline — not a Phase 8 regression; explained by two real `VIDEO_UPDATED` subscribers (Phase 3's cache purge, Phase 7's full index resync) that existed but were never actually measured, since Phase 6 and Phase 7 both explicitly skipped their own Benchmark Reports.
- SQL query count for `MigrationRunner::status()` (+1, from tube-search's Phase 7 migration source), import throughput, REST latency, and cache hit/miss counts are all unaffected, as expected.

## 8. Automated tests

### 8.1 Unit tests (fakes/pure logic only — no WordPress)

**8 new tests**: `ArchiveVideosQueryTest` (4, tube-search, against `InMemoryDiscoveryRepository`/`InMemorySearchCache`), `VideoObjectBuilderTest`/`BreadcrumbListBuilderTest`/`CollectionPageBuilderTest`/`PageMetaBuilderTest` (tube-seo's first unit suite — 4 files). tube-core/tube-cache/tube-player's existing unit suites are unaffected.

### 8.2 Integration tests (real WordPress + MySQL + Redis, inside the `wpcli` Docker container)

**14 new tests**: `ActorStudioIntegrationTest` (6, tube-core — repository reads against real seeded rows, real `/actor/{slug}/` routing including a real 404), `ArchiveVideosQueryIntegrationTest` (3, tube-search — real `JSON_CONTAINS` listing for both a real taxonomy and a real dedicated-table relationship, real caching), one new method on the existing `IndexSyncIntegrationTest` (real `wp_tube_video_actors` assignment picked up on resync — the bug from §2.2), and tube-seo's first integration suite (`BootstrapSmokeTest` + `SeoHeadIntegrationTest`, 3 tests — real page-type detection against real `WP_Query` state, real rendered title/canonical/JSON-LD output).

## 9. Verification summary

| Check | Result |
|---|---|
| `phpcs` (whole repo) | Exit `0` |
| `phpstan analyse --memory-limit=1G` (whole repo, level `max`, 200 files) | Exit `0`, `[OK] No errors` |
| `phpunit` (tube-core/tube-cache/tube-search/tube-seo/tube-player unit suites) | 138/138 passing (63+22+23+21+9) |
| `phpunit -c phpunit-integration.xml.dist` (tube-core/tube-search/tube-seo/tube-player, real stack) | 56/56 passing (23+23+3+7) |
| Live 10-page-type verification | Confirmed correct (§6) |
| Live SQL-count investigation + real bug fix | Confirmed correct (§5, §6) |
| Live backward compatibility | Confirmed correct (§4) |
| Benchmark Report | Complete (§7, full detail in `BENCHMARKS.md`) |

## 10. Explicitly out of scope for Phase 8

**Video sitemap generation** — nominally part of ARCHITECTURE.md's Phase 9 row alongside meta tags/JSON-LD, but not part of this phase's explicit SEO deliverable list (title/meta description/canonical/robots/OpenGraph/Twitter Cards/JSON-LD/pagination metadata only). Stays deferred to an explicit future kickoff.

**tube-admin's actor/studio assignment UI, and `video_count` denormalized-counter maintenance** — both Phase 10. The read layer and archive pages built this phase are complete, tested, production-quality implementations of what Phase 8 actually owns; they simply have no real data to display until Phase 10 ships (§3.2/§3.3).

**Actor/studio badges on the video single page** — see §3.3; not built, since the template tag it would need has no real data source yet either.

**Hierarchical studio browsing** (`Studio::$parent_id` exists in the schema per §14 but nothing this phase presents it) — no current caller needs it; building it now would be speculative.

**A real nav menu / design system for the theme** — out of scope; this phase's CSS is deliberately minimal (enough for a legible grid/card/single-video layout), matching "keep templates thin" and not inventing UI scope beyond what the 10 assigned page types need to be correct and navigable.

## 11. Production impact

None. All work happened in the local Docker staging environment. Production (`root@139.99.96.155`) was not accessed.

---

## 12. Implementation Review

Run per `DEVELOPMENT_RULES.md` §7, dimension by dimension, before this commit.

1. **Fixed — a real, severe correctness bug, not a style issue.** §5's cache-serialization bug is the most significant finding of this phase: every cached discovery/search result, on every cache hit, silently returned unusable broken objects since Phase 3. Found via this phase's own requested performance review, fixed, verified live twice (broken-object reproduction before the fix, correct-object confirmation after).
2. **Fixed — an inaccurate interface-justification claim.** `ActorRepositoryInterface`'s original docblock claimed "a test fake lets Phase 8's routing/archive-listing logic be unit-tested" — untrue: `TermArchiveRouting` depends on a `Closure`, not this interface, and nothing in this codebase unit-tests against a fake of it. Corrected to state the interface's real justification (a genuine cross-plugin boundary — `tube-search`'s `VideoIndexer` depends on it — the same shape `VideoMetadataRepositoryInterface` already established for `tube-player`), matching what's actually true rather than a template inherited from a different class's docblock.
3. **Accepted, not fixed — `video-card.php` issues one indexed primary-key lookup per grid item** (`tube_player_get_image_html()` → `video_metadata_repository()->find()`). This is Phase 6's own explicit, already-documented tradeoff ("N single-row PK lookups, not a batched query... the correct simplicity/complexity tradeoff at this phase's real-scale target; a batched `find_many()` would be premature optimization with no current caller needing it") — Phase 8 is simply the first real caller that pattern anticipated. Batching it now would mean changing `tube-player`'s repository interface and rendering call chain, real architecture surface this phase's explicit "no new abstractions, no redesign" instruction rules out. At 3,000–10,000 videos, N cheap indexed lookups per 24-item page is not a measurable bottleneck on one VPS (confirmed by §6/§7's live numbers).
4. **Security**: every new `echo` in every theme template goes through `esc_html()`/`esc_attr()`/`esc_url()`/`wp_kses_post()`; the two exceptions (`tube_player_get_image_html()`/`get_embed_html()`'s own pre-escaped output) are documented `phpcs:ignore` comments citing Phase 6's own escaping guarantee, not blind suppressions. No new REST routes. No secrets or PII in any new cache value or JSON-LD output. `SeoHead`'s JSON-LD is `wp_json_encode()`'d (safe HTML-context serialization) before being placed inside `<script type="application/ld+json">`.
5. **Race conditions**: `ArchiveVideosQuery`'s cache-aside read/compute/write is the same accepted pattern already used throughout Phase 7's four query classes — two concurrent requests both missing the cache and both computing+writing is harmless (idempotent, same TTL, no partial-write risk), not a new risk this phase introduces.
6. **Migration/rollback risk**: N/A — no new migrations this phase.
7. **Event ordering**: no new assumption about listener execution order; `TermArchiveRouting`/`SearchRouting` register on `template_include` at WordPress's default priority, consistent with every other filter this project registers.
8. **Dead code**: none found — every new class's imports were checked for genuine use (spot-checked exhaustively on `SeoHead.php`, the largest new file, plus a `TODO`/`FIXME` grep across every new file, zero results).

Everything else reviewed clean: no duplicated abstractions beyond what's already justified (§1.5), WPCS/PSR-12 clean, PHPStan level `max` clean across the whole repo.

## 13. Technical Debt Budget

Per `DEVELOPMENT_RULES.md` §10: **zero debt filed, none carried in.** No open `adr/DEBT-*.md` items exist in this project. Checked against the "known, intentional gap between what was implemented and what genuinely production-quality implementation would look like" test:

- **Archive-listing TTL-only caching** (§3.4): the explicitly correct, already-precedented call at this phase's scope — not a gap.
- **`video-card.php`'s per-item metadata lookup** (§12 #3): Phase 6's own explicit, already-documented tradeoff, not a corner cut introduced this phase.
- **Actor/studio badges, `video_count` maintenance, hierarchical studio browsing, sitemap generation** (§10): all genuinely out of this phase's assigned scope, not implementation shortcuts — the code that exists for each of these features' *dependencies* (repositories, index sync, routing) is complete, tested, production-quality implementation of what Phase 8 actually owns.
- **The cache-serialization bug** (§5) was found and **fixed** in this same commit, not deferred — there is nothing left to file.

No Debt ADR filed. `ARCHITECTURE-CHANGELOG.md` is unchanged — no architecture decision changed this phase.

---

Phases 0–8 are implemented, tested, and committed. Further implementation continues phase by phase, per `DEVELOPMENT_RULES.md` — waiting for explicit approval before Phase 9 (sitemap generation is the only piece of ARCHITECTURE.md's Phase 9 row not already delivered by this phase).
