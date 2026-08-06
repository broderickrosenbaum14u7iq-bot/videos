# Phase 11 — Performance validation and hardening (no new features, no architecture changes)

Status: **Complete.** Not a build phase — a validation/hardening phase, per the explicit kickoff instruction: profile the whole project, verify every benchmark, review SQL/indexes/Redis/caching, search for N+1 queries and unnecessary allocations, verify memory usage/cron jobs/import throughput/sitemap/search/archive/admin performance, verify security assumptions, verify migration and rollback safety — optimizing only where benchmark evidence proves a real improvement, against the confirmed production target (single VPS, 3,000–10,000 videos, a few million pageviews/month, Redis, MySQL, Cloudflare CDN — no read replicas, no partitioning, no Elasticsearch, no Kubernetes, no distributed infrastructure). The architecture stayed frozen throughout; nothing in this phase is a §8 ADR-triggering change.

---

## 1. Architecture Drift Report

Re-verified against the actual code, fresh, both before this phase's work started and again against everything this phase introduced.

**Before**: clean — the parent commit (`e6b93e6`, Phase 10) left no known drift.

**After** (against this phase's diff):

1. **No circular dependencies** — no plugin's `composer.json` changed. `tube-cache`'s new `VIDEO_STREAM_STATUS_CHANGED` constant is a literal string matching `Tube_Core\Events\EventCatalog::VIDEO_STREAM_STATUS_CHANGED`'s value, the same no-package-dependency pattern its sibling constants (`VIDEO_PUBLISHED`, `VIDEO_UPDATED`, etc.) already use — not a new coupling.
2. **No service locator pattern** — `tube_player_prime_video_metadata()`/`tube_theme_prime_video_grid()` call `Tube_Core_Plugin::instance()->video_metadata_repository()` from template-tag functions, the same composition-root-adjacent call site every other template tag in these files already uses (`tube_player_get_image_html()` itself calls `Tube_Core_Plugin::instance()` the same way) — not a new pattern, not called from inside unrelated business logic.
3. **No hidden singleton growth** — `VideoMetadataRepository::$cache` is an **instance** property on a class already served as a request-lifetime singleton by `Tube_Core\Plugin::video_metadata_repository()`'s existing lazy-construct-and-return-cached accessor. It is memoization within the already-reviewed singleton, not a new static/global. No other class in this phase's diff gained static state.
4. **No God classes** — `Tube_Core\Plugin`'s accessor count is unchanged (Migration009 is a registered migration, not a new accessor). `CachePurgeSubscriber` gained exactly one handler method, matching the shape of its four existing ones.
5. **No duplicated abstractions** — `FakeNodeConnection`/`FailingPredisClient` exist once per plugin (`tube-cache`, `tube-core`) rather than shared, which is this project's standing rule applied, not a violation: test code is never shared across a plugin's own Composer boundary (§2), the same reasoning `FailingPredisClient`'s own docblock already documents.
6. **No unnecessary interfaces** — no new interface was introduced this phase.
7. **No premature optimization** — `VideoMetadataRepository`'s new cache and the theme's new priming calls are justified by an actual, demonstrated N+1 pattern (§5 below), proven via query-count-delta integration tests, not spec work. Migration009's two indexes are justified by `StatisticsDashboardScreen::SORTABLE_COLUMNS`'s real, already-shipped `ORDER BY views_today`/`ORDER BY views_30d` sort options having no covering index. Two candidate optimizations that were *not* backed by a real, present need — batching `VideoIndexer`'s per-video queries, and building fragment/edge caching — were identified and explicitly rejected rather than built; see `BENCHMARKS.md`'s "Rejected optimizations" section for the full reasoning per optimization. This criterion is exactly the review that produced those two rejections.
8. **No plugin-boundary violations** — every change stays within its own plugin's files, reaching other plugins only through already-public accessors (`Tube_Core_Plugin::instance()->x()`) exactly as before. No plugin queries another plugin's tables directly; no plugin's `composer.json` was touched.

**Result: clean**, both before and after this phase's work.

---

## 2. Scope carried out

Every item from the kickoff instruction's scope list, and where its result lives:

| Scope item | Result |
|---|---|
| Profile the whole project | §1 (drift), §3 (findings), whole-repo `phpcs`/`phpstan` (§8) |
| Verify every benchmark | §6, `BENCHMARKS.md` "Phase 11" section |
| Review all SQL queries / verify indexes | §3.1, §3.4 |
| Verify Redis usage / cache hit-miss behavior | §3.2, §3.3 |
| Search for N+1 queries | §3.1 (fixed), §3.5 (found, rejected — CLI-only, no benchmark evidence) |
| Search for unnecessary allocations | Covered by the Implementation Review (§4) — none found beyond what's already noted |
| Verify memory usage | `BENCHMARKS.md`: 53.313 MB peak, unchanged from Phase 10 |
| Verify cron jobs | §3.6 (found and fixed: `index:rebuild` was a noop placeholder) |
| Verify import throughput | `BENCHMARKS.md`: 1,166.92–1,224.87 items/second, unchanged |
| Verify sitemap generation | `tube-seo` integration suite (14/14, including a stale-file-deletion test) re-run clean, §7 |
| Verify search performance | `tube-search` integration suite (23/23) re-run clean; live `index:rebuild` run confirmed working, §7 |
| Verify archive/admin performance | Live-verified (§7): `latest`/`most-viewed`/`trending`/search/homepage templates all `HTTP 200`, no errors |
| Verify security assumptions | §3.7 |
| Verify migration safety | §3.4 |
| Verify rollback safety | §3.4 (all 9 tube-core migrations, including the new Migration009) |

---

## 3. Findings and fixes

### 3.1 Theme grid N+1 (fixed)

`tube_player_get_image_html()`/`tube_player_get_embed_html()` each call `VideoMetadataRepositoryInterface::find()` once per video — called once per card inside every theme grid loop (homepage, every archive page, search, related videos: 7 call sites total). Unbatched, this is one query per card on exactly the highest-traffic pages.

Fixed with a request-lifetime, in-process memoization cache on `VideoMetadataRepository` (not a new caching *service* — an in-memory array on the already-existing request-lifetime repository singleton, so it satisfies "no new services") plus a new `tube_theme_prime_video_grid()` theme helper that calls `find_many()` once before each of the 7 grid loops to warm the cache. A cache miss still falls through to a real query, so correctness never depends on priming having run first.

**A real bug was found and fixed in this same change** — see §4.

### 3.2 Redis exception handling too narrow (fixed)

`RedisCache` and `RedisViewCounter::record()` each caught only `Predis\Connection\ConnectionException`, missing `Predis\Response\ServerException` — Redis's own error responses, e.g. the `OOM command not allowed when used memory > 'maxmemory'` rejection ARCHITECTURE.md §18.5 already documents as a real risk on this project's single, fixed-RAM VPS target. A server-side error would have thrown uncaught out of a live page render instead of degrading like every other Redis failure mode. Widened both to catch `Predis\PredisException` (the real base both exception types share — verified by reading Predis's actual source, not assumed). `RedisViewCounter::flush()` deliberately keeps its narrow, single-exception catch (a documented, expected race, not a general degrade-on-any-failure path).

### 3.3 Missing cache-purge subscriber (fixed)

`tube_core.video.stream_status_changed` has fired since Phase 5 (`StreamStatusUpdater`) but had no `tube-cache` subscriber — a cached "not ready yet" video-detail response could persist for up to its full TTL after the video actually became playable. Added a handler purging only `CacheKeys::video_detail($video_id)`, matching ARCHITECTURE.md §16.1's exact row for this event (not related-videos, not any listing key). Live-verified end-to-end against the real event dispatcher and real Redis (§7).

### 3.4 Missing index for a shipped sort option (fixed)

`StatisticsDashboardScreen::SORTABLE_COLUMNS` (Phase 10) already exposes `views_today`/`views_30d` as sortable columns, but `wp_tube_video_statistics` (Migration006) never indexed either — its own docblock had explicitly deferred that "until a real query needs it," and Phase 10 shipped that real query. Added `Migration009AddVideoStatisticsWindowIndexes` (additive `dbDelta()` re-`CREATE TABLE`, the same pattern `Migration004` already established). Round-trip tested (`up()` → `down()` → `up()`) against a cloned scratch database before being applied to the real staging database, confirming indexes appear/disappear/reappear correctly and row data is untouched throughout; then applied for real (§7). All 9 tube-core migrations' rollback safety is now individually verified this way (001–008 were verified in prior phases; 009 this phase).

### 3.5 VideoIndexer N+1 in the nightly index:rebuild job (found, rejected — see BENCHMARKS.md)

`VideoIndexer::index()` issues ~6–7 queries per video, called once per video in the CLI-only `index:rebuild` loop. Not fixed this phase: no benchmark evidence of a real problem at the confirmed production target's scale, runs on no user-facing request path, and batching it would only benefit the bulk caller of a class deliberately shared with the incremental (always-one-video) caller. Full reasoning in `BENCHMARKS.md`'s "Rejected optimizations" section.

### 3.6 Nightly index:rebuild cron was a noop placeholder (fixed)

`ops/cron/staging.cron` ran `wp eval 'echo "[noop]..."'` instead of the real `wp tube-search index:rebuild` command that has existed since Phase 7. Fixed to call the real command. Live-verified by running it against the real staging database (§7).

### 3.7 Security assumptions (independently spot-checked, no findings)

Spot-checked directly (not just accepting the earlier audit's conclusion): grepped for any `$wpdb` query built from directly interpolated variables outside `prepare()` — none found. Verified every `tube-admin` `admin_post_*`/`admin-post.php` handler (`VideoDetailsScreen`, `BulkToolsScreen`, `ImportDashboardScreen`) checks both `current_user_can(Plugin::CAPABILITY)` and `check_admin_referer()` before any write. Verified `PosterUploadService`'s upload path goes through WordPress core's own `wp_handle_upload()` (which validates file type/extension) with a `finally`-guaranteed `wp_delete_file()` cleanup of the temp file. `RateLimiter` (tube-cache) was found fully implemented but with no live callers anywhere in the 6 plugins — confirmed via grep, not removed (plausible pre-built infrastructure, not evidence of dead code) and not wired to anything new (out of scope). Noted in `BENCHMARKS.md` so it isn't later mistaken for an oversight.

---

## 4. A real bug found by this phase's own Implementation Review

`VideoMetadataRepository`'s new in-process cache (§3.1) initially had **no cache invalidation on any of its own write methods** (`create()`, `update_status()`, `update_images()`, `update_thumbnail_time()`). A `find()` call that cached a "no row yet" `null` — or a stale pre-update row — before one of those writes ran would then keep returning the stale cached value to any later `find()` in the same request, instead of the row the write had just produced.

This was not caught by writing the feature — it was caught by actually running the full test suite as part of this phase's Implementation Review (dimension: race conditions / cache correctness), which surfaced as **`tube-player`'s and `tube-seo`'s own, unrelated integration tests failing** (both call `find()` before `create()` against a shared repository instance in their own test setup, exactly the sequence that exposes the bug). Investigated back to the actual root cause in `VideoMetadataRepository` rather than treated as a flaky/unrelated failure.

Fixed by `unset($this->cache[$video_id])` at the end of each of the four write methods — the simplest correct fix (re-query on next read, never try to reconstruct the written value in-memory). Two new regression tests were added directly against this contract (`test_find_after_create_reflects_the_new_row_even_when_find_cached_no_row_first`, `test_find_after_update_status_reflects_the_change`) so this exact class of bug can't reappear silently. All three previously-failing suites (`tube-player` 7/7, `tube-seo` 14/14, `tube-core` 38/38) pass after the fix.

---

## 5. Live verification

- **`index:rebuild`**: ran the real (now-fixed) command against the real staging database — `Success: Indexed 1 video(s), removed 0 stale row(s).`
- **Stream-status cache purge**: seeded a real cache entry for `CacheKeys::video_detail(4)` against real Redis, fired `do_action('tube_core.video.stream_status_changed', ['video_id' => 4, 'status' => 'ready'])` through the real dispatcher, confirmed the real subscriber purged exactly that key — `RESULT: PASS (purged)`.
- **Migration009**: round-trip tested against a cloned scratch database (see §3.4), then applied for real; confirmed live via `SHOW INDEX` that both `views_today_idx`/`views_30d_idx` exist and `wp tube migrate status` shows `009` applied.
- **Grid pages**: `GET /` (homepage), `GET /watch/test-video-one/`, `GET /?s=test` all `HTTP 200`. Created three temporary real pages (one per `latest.php`/`most-viewed.php`/`trending.php` page-template), confirmed each `HTTP 200`, then deleted all three and confirmed zero residual rows.
- Scanned the real `wordpress` container's logs across every verification step above — zero errors, warnings, or fatals.

---

## 6. Benchmark Report

Run per `DEVELOPMENT_RULES.md` §9. Full results, methodology, and analysis in `BENCHMARKS.md`'s new "Phase 11" section (append-only, not reproduced here). Summary: **every tracked metric is unchanged from Phase 10 within normal run-to-run noise** — expected, since every fix in this phase either only changes behavior during a Redis failure (never during the healthy operation every benchmark run measures) or is additive (new indexes; a strict-subset-of-prior-queries cache). The theme grid N+1 fix has no visible effect on this harness specifically because staging's fixture data has exactly one published video (a 1-item grid cannot exhibit N+1 either way); it's instead proven correct by three query-count-delta integration test assertions. Two candidate optimizations were benchmarked against and explicitly rejected, with reasoning, rather than built speculatively — see `BENCHMARKS.md`'s "Rejected optimizations" section.

## 7. Automated tests

### 7.1 Unit tests (pure logic only — no WordPress)

**165 tests passing** across all 6 plugins (65 tube-core + 32 tube-cache + 9 tube-player + 23 tube-search + 27 tube-seo + 9 tube-admin). New this phase: `RedisCacheTest` (8, tube-cache — get/set/delete/increment × ConnectionException/ServerException), `RedisViewCounterTest` (2, tube-core — record() × both exception types), `CachePurgeSubscriberTest` additions (2, tube-cache — the new stream-status-changed handler).

### 7.2 Integration tests (real WordPress + MySQL, inside the `wpcli` Docker container)

**84 tests passing** across the 5 plugins with integration suites (38 tube-core + 7 tube-player + 23 tube-search + 14 tube-seo + 2 tube-admin; `tube-cache` has no integration suite, pre-existing, unrelated to this phase). New this phase: 5 tests in `VideoMetadataRepositoryIntegrationTest` (3 proving the batching/caching behavior via `$wpdb->num_queries` deltas, 2 regression tests for §4's bug).

---

## 8. Verification summary

| Check | Result |
|---|---|
| `phpcs` (whole repo) | Exit `0` |
| `phpstan analyse --memory-limit=1G` (whole repo, level `max`) | Exit `0`, `[OK] No errors` |
| `phpunit` (all six plugins' unit suites) | 165/165 passing |
| `phpunit -c phpunit-integration.xml.dist` (5 plugins) | 84/84 passing |
| Live verification (§5) | Confirmed correct against the real staging stack |
| Benchmark Report | Complete (§6, full detail in `BENCHMARKS.md`) |
| Migration rollback safety | All 9 tube-core migrations verified, including the new Migration009 |
| `git status` | Clean except this phase's intended files |

## 9. Explicitly out of scope for Phase 11 (per the kickoff instruction)

Read replicas, MySQL native partitioning, Elasticsearch, Kubernetes, message brokers, any new service, and any architectural rewrite — none of these were introduced, and none were needed: nothing found during this phase's audit required them at the confirmed production target's scale (single VPS, 3,000–10,000 videos, a few million pageviews/month). Fragment/edge caching for the theme was considered and explicitly rejected as new functionality outside a validation/hardening phase's scope (`BENCHMARKS.md`).

## 10. Production impact

Every fix in this phase either closes a real correctness gap that would only surface under a failure condition not exercised by normal operation (Redis memory pressure, a missed cache purge on a specific event) or improves the cost of an existing, already-shipped hot path (the theme grid render) without changing its behavior. None of it changes what any page returns to a normal request today; all of it should reduce risk and cost at the confirmed production target's real scale (3,000–10,000 videos, meaning grids of 12–24 cards instead of staging's 1-video fixture).

## 11. Technical Debt Budget

Zero undocumented debt. Two considered-and-rejected optimizations are documented with full reasoning in `BENCHMARKS.md` rather than silently deferred (§3.5, and the fragment-cache rejection). `RateLimiter`'s no-callers state (§3.7) is noted, not fixed, since fixing it would mean either deleting infrastructure with no evidence it's dead, or wiring it to a feature that doesn't exist yet — both out of scope for a validation/hardening phase.

---

**Stop condition met: Phase 11 complete. Not beginning Phase 12, per the kickoff instruction.**
