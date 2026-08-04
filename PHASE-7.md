# Phase 7 — tube-search (discovery layer: search index, related/trending/most-viewed/recently-added, full-text search)

Status: **Complete.** Implements exactly `ARCHITECTURE.md` §2.6/§12's Phase 7 scope: the denormalized `wp_tube_search_index` table, event-driven incremental sync plus a bulk `index:rebuild` WP-CLI command, and the discovery query layer (related videos, trending, most viewed, recently added, full-text search) exposed only as template-tag helpers — no business logic in the theme. Built under the same real-scale instruction as Phases 4–6 (3,000–10,000 videos, one VPS, Redis, Cloudflare Stream) with an explicit algorithmic spec for related videos (category → actor → studio → tag → random fallback, never the source video) and explicit constraints: trending/most-viewed read only from tube-core's precomputed `wp_tube_video_statistics` (no runtime aggregation, no new statistics table), caching goes through tube-cache with automatic invalidation on publish/update/delete/metadata-change, and the theme surface is limited to five template-tag functions.

---

## 1. Architecture Drift Report

Confirmed clean against the codebase as Phase 6 left it, and re-confirmed after this phase's work:

1. **No circular dependencies** — `grep` for `Tube_Search\` inside tube-core/tube-cache/tube-player finds only docblock prose (explaining which future consumer a cache-key builder is for), never a real `use` import or method call. tube-search's own `composer.json` has no dependency on tube-core's or tube-cache's package — the same plugin-independence rule Phase 6 established for tube-player.
2. **No service locator pattern** — confirmed.
3. **No hidden singleton growth** — `Tube_Core\Plugin`: 408 lines / 6 lazy accessors (added `video_statistics_repository()` this phase). `Tube_Search\Plugin` (new): 271 lines / 8 lazy accessors (5 public, 3 private) — at, not past, §19.2's 6–8-accessor reconsideration trigger; flagged explicitly in the class's own docblock since every one of them is still construct-or-return-cached, nothing more.
4. **No God classes** — no file in this phase's diff crosses any prior phase's size, and the one class doing real orchestration work (`RelatedVideosFinder`) is under 165 lines.
5. **No duplicated abstractions** — `PopularVideosQuery` deliberately holds both "Trending" and "Most Viewed" in one class (they differ only in which `PopularityRepositoryInterface` method and cache key they use; batch lookup, re-ordering, and caching are identical) rather than two near-identical classes.
6. **No unnecessary interfaces** — every new interface (`SearchIndexRepositoryInterface`, `DiscoveryRepositoryInterface`, `SearchCacheInterface`, `PopularityRepositoryInterface`) has exactly one real implementation and a genuine PHPUnit test-fake consumer that could not otherwise be tested without WordPress/Redis/tube-core.
7. **No premature optimization** — `find_by_ids()`/`find_random()`'s full-table-scan `JSON_CONTAINS()`/`ORDER BY RAND()` queries are accepted, not indexed, at this phase's explicit 3,000–10,000-video real-scale target — the same "measured against the actual target scale" reasoning Phase 4 already applied to skipping MySQL partitioning.
8. **No plugin-boundary violations** — tube-search never queries `wp_tube_video_statistics` directly; `TubeCorePopularityRepository` goes through tube-core's own `video_statistics_repository()->top_by_views_total()`/`top_by_views_7d()`. tube-search never purges its own cache reactively; only `Tube_Cache\Events\CachePurgeSubscriber` (already the sole cache-purge authority since Phase 3) was extended.

**Result: clean**, both before and after this phase's work.

---

## 2. What was built

### 2.1 tube-core (small addition, exposing the read path Phase 7 needs)

- **`VideoStatisticsRepositoryInterface::top_by_views_total()`/`top_by_views_7d()`** — an indexed `ORDER BY {views_total|views_7d} DESC LIMIT`, per this phase's explicit "read only from the precomputed statistics table, no runtime aggregation" instruction. `Migration006CreateVideoStatisticsTable`'s own Phase-4 docblock had already predicted this exact design.
- **`Plugin::video_statistics_repository()`** — the same public-accessor shape as `video_metadata_repository()` (Phase 6), now the one sanctioned way any other plugin reads view-count rankings.

### 2.2 tube-cache (extensions, no new files)

- **`CacheKeys`**: 5 new static key builders — `related_videos(int)`, `trending()`, `most_viewed()`, `recently_added()`, `search(string, int, int)`.
- **`CachePurgeSubscriber`**: extended to purge tube-search's new keys on the video lifecycle it already listens to, plus one new subscription:
  - `purge_video()` (video detail's own purge, reused by published/updated/deleted) now also purges `related_videos($video_id)`.
  - `handle_video_published()` also purges `recently_added()`.
  - `handle_video_deleted()` also purges `trending()`/`most_viewed()`.
  - New `handle_video_stats_rolled_up()`, subscribed to `tube_core.video.stats_rolled_up`, purges `trending()`/`most_viewed()` only — never an individual video's own entry, per §16.1.

### 2.3 tube-search: the search index (`Tube_Search\Index`)

- **`Migration001CreateSearchIndexTable`** — creates `wp_tube_search_index` exactly per §2.6's frozen schema (`FULLTEXT KEY` on title+description, indexed `published_at`/`views_total`).
- **`SearchIndexRow`** — a readonly DTO snapshot of one row; the shape every discovery query returns.
- **`CandidateColumn`** — a backed enum (`category_ids`/`tag_ids`/`actor_ids`/`studio_ids`) parameterizing `find_by_ids()` across the cascade's four structurally-identical JSON-array columns, instead of four near-duplicate methods.
- **`SearchIndexRepositoryInterface`** (write side) / **`DiscoveryRepositoryInterface`** (read side), one concrete **`SearchIndexRepository`** implementing both — genuinely different real consumers (event sync vs. discovery queries) on one physical table, not artificial interface splitting.
- **`VideoIndexer`** — denormalizes one video's post/taxonomy/tube-core-metadata data into the index, or removes it if the video is no longer published. Shared by both sync paths (event-driven and bulk rebuild) so they can never drift apart in what they write.

### 2.4 tube-search: sync (`Tube_Search\Events`, `Tube_Search\CLI`)

- **`SearchIndexSyncSubscriber`** — subscribes to tube-core's `VIDEO_PUBLISHED`/`VIDEO_UPDATED`/`VIDEO_DELETED`/`VIDEO_STREAM_STATUS_CHANGED`/`VIDEO_STATS_ROLLED_UP` by hook-name string (not a typed `Dispatcher` dependency), the same pattern `CachePurgeSubscriber` established in Phase 3. Published/updated trigger a full resync; stream-status-changed and stats-rolled-up use cheap single-column updates instead of a full resync.
- **`IndexCommand`** (`wp tube-search index:rebuild`) — bulk (re)builds the entire index in batches, and removes any indexed video no longer actually published (a diff against `all_video_ids()`, since the publish-status query can only add/update currently-published videos).

### 2.5 tube-search: caching (`Tube_Search\Cache`)

- **`SearchCacheInterface`** (own 2-method interface, not tube-cache's) / **`TubeCacheAdapter`** — a thin pass-through to `Tube_Cache\Plugin::instance()->cache()`. The decoupled-interface pattern from Phase 6: tube-search's own PHPUnit suite has no composer dependency on tube-cache's package, so any class needing a cache in a unit test depends on this interface, never `Tube_Cache\Cache\CacheInterface` directly.

### 2.6 tube-search: discovery (`Tube_Search\Discovery`, `Tube_Search\Search`)

- **`PopularityRepositoryInterface`** (own interface) / **`TubeCorePopularityRepository`** — the same decoupled-interface pattern, wrapping tube-core's `video_statistics_repository()`.
- **`RelatedVideosFinder`** — the priority cascade: same categories → same actors → same studio → similar tags → random fallback, each step filling only the slots earlier steps left empty, never duplicating a video, never returning the source video. Cached per video ID, 15-minute TTL, purged reactively only for the source video's own cache entry (§16.2's bounded-staleness philosophy: a different video newly qualifying as "related" waits out the TTL rather than triggering an unbounded fan-out purge).
- **`PopularVideosQuery`** — "Trending"/"Most Viewed" in one class: ranks by tube-core's statistics (2 queries total regardless of result size — no N+1), re-sorts the unordered batch lookup back into tube-core's original rank.
- **`RecentlyAddedQuery`** — indexed `published_at DESC`, cache-first, purged on every publish.
- **`SearchQuery`** — full-text search via `MATCH() ... AGAINST() IN NATURAL LANGUAGE MODE`; validates/clamps paging, cached with a TTL only (deliberately never purged reactively — the query-text key space is unbounded, the same deep-page philosophy §16.2 already applies elsewhere).

### 2.7 tube-search: composition root and theme API

- **`Plugin`** — registers the migration, wires the sync subscriber, registers the CLI command, and exposes 8 lazy accessors (5 public, 3 private).
- **`includes/template-tags.php`** — the only theme-facing surface: `tube_search_related_videos()`, `tube_search_trending()`, `tube_search_most_viewed()`, `tube_search_recently_added()`, `tube_search_query()`. Each is a thin delegation to a `Plugin` accessor — no business logic.

---

## 3. Design decisions

1. **"Trending" and "Most Viewed" both read tube-core's statistics table, on different columns.** The user instruction named the statistics table for both without naming columns; resolved by finding `Migration006CreateVideoStatisticsTable`'s own Phase-4 docblock had already predicted this exact split (`views_7d` for Trending, `views_total` for Most Viewed) — not a guess, a pre-existing architectural commitment.
2. **`wp_tube_search_index` holds published videos only.** `VideoIndexer::index()` actively deletes a non-published video's row rather than leaving it stale, because `VIDEO_UPDATED` fires on every save (including drafts) and a previously-published video can be unpublished later.
3. **Actor/studio candidates in the cascade come from the index's own `actor_ids`/`studio_ids` columns, which stay empty until tube-admin (Phase 10) assigns any** — `VideoIndexer` preserves whatever the existing row already has on every resync rather than trying to derive them from anywhere else. The cascade logic is fully built and tested now; it simply has no real candidates to rank by actor/studio yet in production.
4. **`JSON_CONTAINS()`/`ORDER BY RAND()` full-table scans, not generated/indexed columns.** `category_ids`/`tag_ids`/`actor_ids`/`studio_ids` are `VARCHAR` columns storing JSON text per §2.6's frozen schema, not a real MySQL `JSON` column type — a B-tree index can't cover a "does this JSON array contain X" predicate without schema changes that would need an ADR. Accepted as fast enough at the explicit 3,000–10,000-video target, the same reasoning Phase 4 applied to skipping partitioning.
5. **Cache-key string literals are hand-duplicated, not shared via a direct import.** Every tube-search query class that needs to match a `Tube_Cache\Cache\CacheKeys` builder exactly reconstructs it as a documented "must-match" literal (`'trending'`, `'related_videos:' . $video_id`, etc.) instead of importing the class — preserving the same plugin-independence property (no tube-cache package dependency in tube-search's `composer.json`) Phase 6 established for tube-player against tube-core.
6. **8 accessors on `Tube_Search\Plugin`**, flagged explicitly rather than silently accepted, at the top of §19.2's 6–8 reconsideration range. Not split, because the actual test in §19.2 ("if `Plugin.php` starts containing real logic instead of wiring, that's the signal to extract") isn't met — every accessor is still construct-or-return-cached, nothing more.

---

## 4. Backward compatibility with Phases 0–6

Verified live, not assumed:

- `wp tube migrate status`: all 8 pre-existing tube-core migrations still `yes`/unchanged timestamps; tube-search's new migration `001` applied cleanly as migration #9 overall.
- All plugins still show `active` with zero fatals after activation.
- tube-core's unit suite (63/63) and integration suite (17/17) pass unchanged. tube-player's integration suite (7/7) passes unchanged.
- tube-cache's unit suite (22/22, including 6 new `CacheKeys` tests and a rewritten 9-method `CachePurgeSubscriberTest`) passes.

## 5. Automated tests

### 5.1 Unit tests (fakes/pure logic only — no WordPress, no live Redis/MySQL)

**19 new PHPUnit tests** for tube-search (a new suite):

- `RelatedVideosFinderTest` (7 tests): category outranks actor; cascade falls through to fill remaining slots; source video never returned; no duplicate across cascade steps; random fallback when nothing matches; unindexed source video still falls back to random; second call reuses the cached result.
- `PopularVideosQueryTest` (5 tests): `trending()` preserves rank order from an out-of-order batch lookup; `most_viewed()` uses the all-time ranking, not the recent one; a ranked ID missing from the index is skipped, not an error; trending/most-viewed cache under separate keys; second call reuses cache.
- `RecentlyAddedQueryTest` (2 tests): first call delegates and caches; second call reuses cache.
- `SearchQueryTest` (5 tests): blank query short-circuits without touching the repository or cache; matching query returns results and caches them; page/per_page clamp to a minimum of 1; different query text uses different cache keys; same query/page reuses cache.

Plus **6 new tests** for tube-cache's `CacheKeysTest` and a fully rewritten 9-method `CachePurgeSubscriberTest` (purge-decision logic per handler, against a fake `CacheInterface`).

### 5.2 Integration tests (real WordPress + MySQL + Redis, inside the `wpcli` Docker container)

**19 new tests** in tube-search's own `tests/Integration`:

- `BootstrapSmokeTest` (2 tests): all three plugins loaded together; `wp_tube_search_index` exists.
- `IndexSyncIntegrationTest` (3 tests): publishing a real video with a real category indexes it correctly; unpublishing removes it from the index; deleting removes it from the index.
- `RelatedVideosIntegrationTest` (3 tests): a real shared `video_category` term produces a related match while an unrelated video is excluded; an actor match outranks a studio-only match (cascade priority, seeded via the repository's own public `upsert()` since no real actor-assignment UI exists yet); result is cached in real Redis.
- `PopularVideosIntegrationTest` (3 tests): trending/most-viewed genuinely read different real statistics columns; a real `tube_core.video.stats_rolled_up` dispatch purges both cached listings; a ranked video missing from the index is skipped, not an error.
- `RecentlyAddedIntegrationTest` (2 tests): real publish-date ordering; publishing a video purges the real cached listing.
- `SearchQueryIntegrationTest` (4 tests): a real MySQL `FULLTEXT` match returns the right video and excludes an unrelated one; blank query short-circuits; a no-match query returns empty; result is cached.
- `CacheInvalidationIntegrationTest` (2 tests): deleting a video purges its own related-videos entry plus the site-wide trending/most-viewed listings, end-to-end against real Redis; deleting one video never touches a different, still-cached video's entry.

## 6. Live verification

- **Both migration and index-rebuild flows**, run against the real staging database: `wp tube migrate up` applied `tube-search: 001` as the 9th migration overall; `wp_tube_search_index` confirmed present via `SHOW TABLES`.
- **The full integration suite**, run inside the `wpcli` Docker container against the real stack: tube-search 19/19, tube-core 17/17, tube-player 7/7 — all real posts/terms/statistics rows created during the run are cleaned up in each test's `tearDown()`.
- **Cross-plugin event wiring**, confirmed live rather than assumed from the unit-tested decision logic alone: a real `wp_insert_post()`/`wp_delete_post()`/`stats_rolled_up` dispatch was traced through to an actual `wp_tube_search_index` row change and an actual Redis key deletion in the same test run.
- **Backward compatibility** (§4): confirmed live.

## 7. Benchmark Report

**Skipped this phase**, per this phase's explicit instruction ("run benchmarks only if search performance is affected"). This phase introduces search/discovery query paths for the first time — there is no prior-phase baseline to regress against, and no existing tracked metric in `DEVELOPMENT_RULES.md` §9 touches tube-search code. `BENCHMARKS.md` is unchanged.

## 8. Verification summary

| Check | Result |
|---|---|
| `phpcs` (whole repo) | Exit `0` |
| `phpstan analyse --memory-limit=1G` (whole repo, level `max`, 152 files) | Exit `0`, `[OK] No errors` |
| `phpunit` (tube-search unit suite) | 19/19 passing |
| `phpunit` (tube-cache unit suite) | 22/22 passing |
| `phpunit` (tube-core unit suite) | 63/63 passing, unaffected |
| `phpunit -c phpunit-integration.xml.dist` (tube-search, real stack) | 19/19 passing |
| `phpunit -c phpunit-integration.xml.dist` (tube-core, real stack) | 17/17 passing, unaffected |
| `phpunit -c phpunit-integration.xml.dist` (tube-player, real stack) | 7/7 passing, unaffected |
| Live migration + index-rebuild verification | Confirmed correct (§6) |
| Live cross-plugin event wiring | Confirmed correct (§6) |
| Live backward compatibility | Confirmed correct (§4) |
| Benchmark Report | Skipped — no prior baseline, not affected (§7) |

## 9. Explicitly out of scope for Phase 7

Actor/studio assignment UI (tube-admin, Phase 10) — the cascade's actor/studio matching is fully implemented and integration-tested against directly-seeded data, but has no real assignment path in production yet. Theme integration/actual template usage — Phase 8. SEO metadata for discovery pages — also Phase 8. All per `ARCHITECTURE.md` §12 and this phase's explicit scoping instruction.

## 10. Production impact

None. All work happened in the local Docker staging environment. Production (`root@139.99.96.155`) was not accessed.

---

## 11. Implementation Review

Kept concise per this phase's explicit instruction — real findings only, no filler.

1. **Fixed — a test assertion, not a production bug.** `RelatedVideosIntegrationTest`'s first live run failed: `test_returns_a_video_sharing_a_real_category` asserted an unrelated video was never returned, but with `limit=5` and only one true category match seeded, `RelatedVideosFinder`'s own documented random-fallback step correctly filled the remaining 4 slots — which legitimately can include the "unrelated" video. The implementation was correct; the test's limit was fixed to `1` (exactly the number of real matches) so the fallback path doesn't engage where it isn't the thing under test.
2. **Two self-caught issues fixed before verification, not left for review**: a stray corrupted-Unicode import line that appeared in a freshly-written `Plugin.php` (caught on re-read, removed before any lint ran), and a retroactive gap in `phpstan.neon.dist` — tube-player's `vendor/autoload.php` had never been added to `bootstrapFiles` back in Phase 6 (latent, not previously symptomatic, since PHPStan's own path-scanning happened to cover tube-player's purely intra-plugin classes). Both fixed; see git history for the exact diffs.
3. **Accepted, not fixed — `find_by_ids()`/`find_random()` are full table scans.** Documented in §3.4 above and in the repository class's own docblock. Correct at this phase's explicit real-scale target; would need a Debt ADR only if a future phase's actual measured scale exceeds it.
4. **Accepted, not fixed — actor/studio cascade candidates don't exist in production yet.** Documented in §3.3/§9. Not a corner cut on this phase's deliverable: the matching logic itself is complete and tested against real seeded data; what's missing is a different phase's UI.
5. **Security**: every dynamic SQL value goes through `$wpdb->prepare()` with `%i`/`%d`/`%s` placeholders; the one interpolated identifier (`CandidateColumn->value`) is a closed backed-enum value, never external input. No new REST routes this phase. No secrets or PII in any new cache value (search-result rows carry only public video metadata already indexed).
6. **Queries/N+1**: `PopularVideosQuery::resolve()` batches its display-field lookup in one `find_many()` call regardless of result-set size — confirmed no per-row query loop. `RelatedVideosFinder::collect()` issues at most one `find_by_ids()` call per cascade step (4 total) plus at most one `find_random()` fallback call — bounded regardless of `$limit`.

Everything else reviewed clean: no duplicated abstractions beyond the deliberate, already-justified cache-key-literal duplication (§3.5), no dead code, WPCS/PSR-12 clean, PHPStan level `max` clean across the whole repo.

## 12. Technical Debt Budget

Per `DEVELOPMENT_RULES.md` §10: **zero debt filed, none carried in.** No open `adr/DEBT-*.md` items exist in this project. Checked against the "known, intentional gap between what was implemented and what genuinely production-quality implementation would look like" test:

- **Full-table-scan cascade/fallback queries** (§3.4/§11 #3): the explicitly correct call at this phase's real-scale target, not a gap — would need its own ADR to reconsider only if measured production scale exceeds the target.
- **Actor/studio cascade has no real candidates yet** (§3.3/§9): not a corner cut on this phase's own deliverable — the code that exists (matching logic, integration-tested) is complete, production-quality implementation of Phase 7's assigned scope; what's missing belongs to Phase 10.
- **Cache-key literal duplication instead of a shared class** (§3.5): the explicitly correct call per the same plugin-independence rule Phase 6 already established, not a gap.

No Debt ADR filed. `ARCHITECTURE-CHANGELOG.md` is unchanged — no architecture decision changed this phase.

---

Phases 0–7 are implemented, tested, and committed. Further implementation continues phase by phase, per `DEVELOPMENT_RULES.md` — waiting for explicit approval before Phase 8.
