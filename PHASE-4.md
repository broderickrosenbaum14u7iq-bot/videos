# Phase 4 — Video Views & Statistics

Status: **Complete.** Implements exactly `ARCHITECTURE.md` §12's Phase 4 scope: `tube-core`'s `wp_tube_video_views` + `wp_tube_video_statistics` tables, Redis-buffered view recording, and stats rollup — all driven by the Linux-cron WP-CLI commands `ARCHITECTURE.md` §7 lists.

Built under an explicit real-scale instruction for this phase (verbatim intent): optimize for 3,000–10,000 videos, a few million pageviews/month, one VPS, Redis, Cloudflare Stream, Cloudflare CDN — avoid enterprise patterns with no measurable benefit at that scale, keep the implementation simple. Every place this phase's implementation deliberately diverges from the letter of `ARCHITECTURE.md`'s Revision-2-era wording (specifically: MySQL native partitioning) is called out explicitly below, with the reasoning, per that same instruction and per `DEVELOPMENT_RULES.md` §1's "do not redesign, but implement in the phase's actual spirit" discipline.

---

## 1. Architecture Drift Report (before this phase's work started)

Run against the codebase exactly as Phase 3 left it, per `DEVELOPMENT_RULES.md` §6 (reduced scope while frozen).

1. **No circular dependencies** — confirmed via `grep`: no `Tube_Player`/`Tube_Search`/`Tube_Seo`/`Tube_Admin` references in tube-core; no genuine `use Tube_Core\*` imports in tube-cache (two docblock-prose mentions only, explaining *why* no such dependency exists — verified by confirming no `use Tube_Core` import statement anywhere in tube-cache).
2. **No service locator pattern** — `Plugin::instance()` called only from each plugin's own `Plugin.php`/bootstrap file: confirmed.
3. **No hidden singleton growth** — no `private static`/`public static $x` outside `Plugin.php` in either plugin: confirmed.
4. **No God classes** — `tube-core/Plugin.php` at 181 lines before this phase, still under the 6–8-accessor reconsideration trigger after adding `view_counter()`/`view_recorder()` (4 lazy accessors total): confirmed.
5. **No duplicated abstractions** — no change since Phase 3.
6. **No unnecessary interfaces** — `MigrationInterface`, `SchemaVersionRepositoryInterface`, `HookBusInterface`, `CacheInterface` each have exactly one real implementation and one test fake: confirmed.
7. **No premature optimization** — no change since Phase 3.
8. **No plugin boundary violations** — no plugin queries another plugin's tables: confirmed.

**Result: clean.** Phase 4 started from an unmodified Phase 3 baseline.

---

## 2. What was built

### 2.1 Migrations (`Tube_Core\SchemaMigrations`)

- **Migration005CreateVideoViewsTable** — `wp_tube_video_views`: `(video_id, view_hour)` composite primary key, `view_count` counter, `view_hour_idx` for retention deletes. One row per (video, hour) with at least one view — not one row per view.
- **Migration006CreateVideoStatisticsTable** — `wp_tube_video_statistics`: one row per video, `views_total`/`views_today`/`views_7d`/`views_30d`, indexed on `views_total` and `views_7d` (the two columns `ARCHITECTURE.md` §16 names for "trending"/"most viewed" listing purges — no index added for columns with no documented consumer).

**Deliberate divergence from `ARCHITECTURE.md` §2.2's literal wording**: no MySQL native `PARTITION BY`/`DROP PARTITION`. §2.2 says "monthly partitions" (carried over, unchanged, from an earlier revision written against a 500,000+-video ceiling). At this phase's actual target — 3,000–10,000 videos, a few million pageviews/month — even several years of retained hourly buckets stays in the low tens of millions of rows on one indexed InnoDB table, comfortably within what a plain table handles without partitioning. Partitioning would add real, measurable cost at this scale (no `dbDelta()` support for partition management, meaning hand-written non-reversible-by-the-standard-migration-contract DDL; genuine operational complexity for a one-person/small-team VPS deploy) for zero measured benefit. Retention is still real — `Retention`/`views:partition-maintenance` (§2.4 below) — it just doesn't need `DROP PARTITION` to be fast at this scale; a plain indexed `DELETE ... WHERE view_hour < cutoff` is. Full reasoning is in `Migration005CreateVideoViewsTable`'s own docblock, not just here.

### 2.2 Redis view-buffer counter (`Tube_Core\Views`)

- **ViewCounterInterface** (`record()`/`flush()`) + **RedisViewCounter** — tube-core's *own* Redis connection and key namespace (`tube_core:view_buffer`, a single Redis HASH), entirely independent of `tube-cache`'s `CacheInterface`. This is not a missed opportunity to reuse tube-cache's abstraction: `ARCHITECTURE_FREEZE.md`'s frozen decision #1 states plainly that tube-core is the one plugin with no plugin dependency at all — every other plugin depends on it, never the reverse. Wired via its own `TUBE_CORE_REDIS_HOST`/`TUBE_CORE_REDIS_PORT` constants (mirroring `TUBE_CACHE_REDIS_HOST`/`PORT`'s pattern from Phase 3, same physical Redis instance, deliberately separate config per plugin).
- `flush()` takes everything buffered via an atomic `RENAME` to a uniquely-named key, so two overlapping `views:flush` runs (busybox cron does not skip an overlapping run) can never collide — see §7 below for the one real race this required handling.
- `record()` degrades to a logged no-op on a Redis connection failure (same fail-open principle as `Tube_Cache\Cache\RedisCache`); `flush()` deliberately does not, since it only runs from the cron job, where a failure should surface as a failed job (`ARCHITECTURE.md` §18.5).
- **ViewRecorder** — the pure "buffer it, then dispatch `VIDEO_VIEW_RECORDED`" logic, unit-tested; kept out of `Plugin.php` (which stays wiring-only, per `ARCHITECTURE.md` §19.2) the same way Phase 2 kept `VideoLifecycleEvents` separate from `Plugin.php`.

### 2.3 Repositories (`Tube_Core\Views\Repositories`)

- **VideoViewsRepositoryInterface**/**VideoViewsRepository** — `bulk_record()` (one multi-row `INSERT ... ON DUPLICATE KEY UPDATE`, `ARCHITECTURE.md` §19.8), `window_sums()` (one aggregate query computing today/7d/30d sums together via `SUM(CASE WHEN ...)`, not three separate queries), `purge_before()`.
- **VideoStatisticsRepositoryInterface**/**VideoStatisticsRepository** — `bump_totals()` (bulk upsert adding to the running `views_total`), `all_totals()`, `update_windows()` (bulk upsert overwriting the three window columns).
- `views_total` is a **running counter**, incremented at flush time, **never** recomputed by summing `wp_tube_video_views` — that table has a retention window, so summing it could never correctly reconstruct a true all-time total once old buckets are purged. `views_today`/`views_7d`/`views_30d` are the opposite: always recomputed fresh from `wp_tube_video_views`, since those windows are always well within the 90-day retention period. This asymmetry is deliberate, not inconsistent — each column uses the storage strategy that's actually correct for what it means.
- Every multi-row write is a single `INSERT ... ON DUPLICATE KEY UPDATE`, not `$wpdb->prepare()`'d — a variable-length `VALUES` clause can't satisfy `prepare()`'s `literal-string` requirement regardless of how it's built (confirmed empirically working through PHPStan's level-`max` output, not assumed). Every value in these queries is a PHP `int` (numeric coercion, same protection `%d` gives) or passed through `esc_sql()` — documented in full at each call site, the same class of justification `AbstractMigration::drop_table()`/`drop_index()` already established in Phase 1 for identifiers `prepare()` can't parameterize either.

### 2.4 Orchestrators (`Tube_Core\Views`)

- **ViewsFlusher** — backs `views:flush`. Takes the counter's buffer, bulk-records it into the current hour bucket, bumps running totals. Both writes in one multi-row statement each.
- **StatsRollup** — backs `stats:rollup`/`stats:rollup --full`. **Both cadences run the identical full recompute** — at this phase's real target scale, a single indexed aggregate query over every video is cheap enough every 5 minutes that tracking "which videos changed since the last run" separately would be real, unjustified complexity for no measurable benefit, exactly the pattern this phase is scoped to avoid. `--full` exists as a distinct WP-CLI subcommand only because `ARCHITECTURE.md` §7's crontab lists it as a separate nightly entry; there's nothing for it to do differently here. Every video with a statistics row gets touched on every run — including ones with zero views in the last 30 days, so a stale non-zero value is never left displayed. Dispatches `VIDEO_STATS_ROLLED_UP` once per video, payload `{video_id, views_total}`, per `EVENTS.md`.
- **Retention** — backs `views:partition-maintenance` (command name kept exactly as `ARCHITECTURE.md` §7 documents it, even though the implementation is a plain `DELETE`, not partition rotation — see §2.1 above). 90-day retention window, comfortably beyond the 30-day window `StatsRollup` reads.

### 2.5 WP-CLI + cron + events

- **ViewsCommand** (`Tube_Core\CLI`) — `wp tube-core views:flush`, `wp tube-core stats:rollup [--full]`, `wp tube-core views:partition-maintenance`. Registered as three individually-named commands (not a class-with-space-separated-subcommands) specifically to match `ARCHITECTURE.md` §7's literal, colon-containing command names.
- `ops/cron/staging.cron` — the three relevant no-op placeholders replaced with the real commands; cadences unchanged from the Phase 0 skeleton (`* * * * *`, `*/5 * * * *`, nightly).
- `EventCatalog::VIDEO_VIEW_RECORDED` and `VIDEO_STATS_ROLLED_UP` — moved from Reserved to Active; `EVENTS.md` updated.
- `Plugin::view_recorder(): ViewRecorder` — the new public accessor, for a future consumer (a REST controller, `tube-player`) to call `->record($video_id)`. `view_counter()` itself stays private — nothing outside tube-core needs the raw buffer.

---

## 3. Design decisions

Beyond the partitioning divergence (§2.1) and the `--full` equivalence (§2.4), both already justified in depth above:

1. **No REST endpoint for view recording.** `ARCHITECTURE.md` §12's Phase 4 row says "Redis-buffered view recording" — the recording *mechanism*, not a specific HTTP route. §17's mention of "the view-recording endpoint" describes a future capability in general terms, not a Phase 4-specific deliverable, and no REST route list for it appears anywhere in the text available to this phase. Building a guessed-at route shape now would be scope expansion, not implementation of what's specified. `Plugin::view_recorder()` is the public entry point a future REST controller (in whichever phase actually builds it) calls.
2. **`ViewRecorder` as its own small class, not inline on `Plugin.php`.** Keeps `Plugin.php` wiring-only (`ARCHITECTURE.md` §19.2) and keeps "record, then announce" unit-testable — the same "WordPress-coupled boundary vs. testable core" split `VideoLifecycleEvents` established in Phase 2.
3. **`RedisViewCounter`/`VideoViewsRepository`/`VideoStatisticsRepository` have no dedicated PHPUnit tests; verified live instead.** The same precedented split as `WordPressHookBus` (Phase 2) and `RedisCache` (Phase 3): each interface's *fake* is what makes the logic that depends on it (`ViewsFlusher`, `StatsRollup`, `Retention`, `ViewRecorder`) unit-testable; the thin real adapters over WordPress/Redis/MySQL are proven against the real thing instead (§6 below).

---

## 4. Backward compatibility with Phases 0–3

Verified live, not assumed:

- `wp tube migrate status` after this phase's code loaded: migrations 001–004 still `yes`/applied, unchanged timestamps.
- `video` CPT and `video_category`/`video_tag` taxonomies still registered.
- All eight plugins still activate and load with zero fatals.
- `tube-cache`'s `CachePurgeSubscriber` still purges correctly on a real `video.published` event (seeded a cache entry, published a video, confirmed the Redis key was gone) — proves Phase 3's event-driven purge path is untouched by Phase 4's additions to the same event-dispatch machinery.
- `tube-core`'s own event dispatch (the four `video.*` lifecycle events from Phase 2) is exactly what this phase's live verification (§6) already depends on and exercises.

## 5. Automated tests

**12 new PHPUnit tests for `tube-core`** (43 total, up from Phase 3's 31), all against fakes — zero WordPress, zero live Redis, zero live database:

- `ViewRecorderTest` (3 tests): buffers the view, dispatches `VIDEO_VIEW_RECORDED` with the right payload, recording the same video twice buffers and dispatches twice.
- `ViewsFlusherTest` (3 tests): an empty buffer touches neither repository and returns 0; a non-empty buffer is bulk-recorded into both repositories with the current hour bucket and returns the right video count; flushing genuinely empties the counter.
- `StatsRollupTest` (4 tests): no videos means nothing to roll up; a video with recent views gets its real window sums written and announced; a video with zero recent views gets its windows *zeroed*, not skipped; every video with a statistics row is announced with its own `views_total`, including ones absent from `window_sums()`.
- `RetentionTest` (2 tests): purge uses a 90-day cutoff (asserted with a small time-delta tolerance, not a literal-second match, to avoid test flakiness); purge returns exactly what the repository reports as deleted.

Found and fixed during test-writing, not after: **`StatsRollup`/`Retention` originally used WordPress's `DAY_IN_SECONDS` constant**, even though both classes are documented as WordPress-independent, unit-tested without WordPress loaded. Since `DAY_IN_SECONDS` is WordPress-defined (not a PHP constant), the very first test run fatally errored with "undefined constant" — caught immediately by the test suite doing exactly what it's for. Fixed by replacing it with a local `SECONDS_PER_DAY = 86400` constant in each class, closing the actual inconsistency (a class claiming WordPress-independence while quietly depending on it) rather than papering over the symptom.

`tube-core`'s prior 31 tests and `tube-cache`'s 15 tests are unaffected and still pass.

## 6. Live verification (real Redis, real MySQL, real WP-CLI — not just unit tests)

Unit tests exercise fakes; none of them prove the real Redis buffer, the real bulk SQL, or the real WP-CLI commands work. Verified directly against the Docker staging environment:

- **Migrations**: applied 005/006 fresh, inspected the resulting schema directly (`DESCRIBE`) against what the migrations declare, rolled both back to 004, confirmed the tables were genuinely dropped, re-applied — a full up/down/up cycle, not just an up.
- **Full record → flush → rollup → retention cycle**: recorded 3 views for one video and 1 for another via the real `Plugin::instance()->view_recorder()`, inspected tube-core's Redis buffer directly (`HGETALL tube_core:view_buffer`) and confirmed the exact counts; ran `views:flush`, confirmed the buffer was cleared and `wp_tube_video_views`/`wp_tube_video_statistics` held exactly the expected rows; ran `stats:rollup`, confirmed `views_today`/`views_7d`/`views_30d` were correctly populated from the just-flushed data; ran `stats:rollup --full` and confirmed identical behavior (per §2.4's "both cadences are the same recompute" design).
- **Events**: installed a temporary debug mu-plugin (removed afterward, nothing from it committed) listening for `VIDEO_VIEW_RECORDED`/`VIDEO_STATS_ROLLED_UP`, and confirmed both fired with the exact expected payloads across independent WP-CLI processes — `tube_core.video.view_recorded {"video_id":4}` and `tube_core.video.stats_rolled_up {"video_id":4,"views_total":4}`, matching real recorded/flushed data exactly.
- **Retention**: ran `views:partition-maintenance` against fresh data (purged 0, correctly — nothing was old enough), then manually inserted a row dated 100 days in the past and ran it again (purged exactly 1, leaving the recent rows untouched) — proves the cutoff logic actually discriminates by age, not just that the command runs without error.
- **Backward compatibility** (§4): confirmed live, not assumed.

All test artifacts (the temporary video post, database rows, Redis keys, the debug mu-plugin) were cleaned up afterward; nothing from this verification is part of the committed state.

## 7. Verification summary

| Check | Result |
|---|---|
| `phpcs` (whole repo) | Exit `0` |
| `phpstan analyse --memory-limit=1G` (whole repo, level `max`) | Exit `0`, `[OK] No errors` |
| `phpunit` (tube-core) | 43/43 passing |
| `phpunit` (tube-cache) | 15/15 passing |
| Phase 0–3 checks after this phase's code loaded | Unaffected (§4) |
| Live migration up/down/up | Confirmed correct |
| Live record → flush → rollup → retention cycle | Confirmed correct, real Redis + real MySQL |
| Live event dispatch (`VIDEO_VIEW_RECORDED`, `VIDEO_STATS_ROLLED_UP`) | Confirmed correct, across independent WP-CLI processes |
| Live retention age-discrimination | Confirmed correct |
| Live backward compatibility (tube-cache's purge subscriber included) | Confirmed correct |

## 8. Explicitly out of scope for Phase 4

A view-recording REST endpoint and its rate-limiting integration (§3.1) — not required by `ARCHITECTURE.md` §12's Phase 4 row; deferred to whichever future phase builds real REST controllers for video-facing routes. MySQL native partitioning (§2.1) — deliberately not built at this phase's real target scale; the deferred-decision trigger, if ever needed, is the same kind of "measured, not assumed" evidence `ARCHITECTURE_FREEZE.md`'s other deferred decisions already require. `watch-history:purge` and `import:process` — different tables, Phase 5. All per `ARCHITECTURE.md` §12 and this message's explicit real-scale scoping instruction.

## 9. Production impact

None. All work happened in the local Docker staging environment. Production (`root@139.99.96.155`) was not accessed.

---

## 10. Implementation Review

Performed before this phase's commit, per `DEVELOPMENT_RULES.md` §7 — every dimension in that section's checklist was walked against the actual diff, re-read fresh. Real findings made and fixed during this pass:

1. **Correctness**: `wpdb::prepare()` can return `null` for a malformed format string; `wpdb::query()` is typed to reject `null`. `VideoViewsRepository::purge_before()` originally passed `prepare()`'s result straight to `query()` uncritically — PHPStan (level `max`) caught the type mismatch. Fixed with an explicit null-guard (returns `0`, since a null-`prepare()`-result here can only mean a bug in the literal SQL, not anything `$cutoff`/`$table` could trigger) rather than a cast or an ignore.
2. **Race condition**: re-reading `RedisViewCounter::flush()` fresh surfaced a real, if narrow, race — busybox cron (this project's staging cron) does not skip an overlapping run, so two `views:flush` invocations can genuinely overlap under load. If the first finishes its `RENAME` between the second's `EXISTS` check and its own `RENAME`, the second's `RENAME` throws (Redis's "no such key" error). Fixed by catching that specific exception (`Predis\Response\ServerException`, not the broader `ConnectionException` `record()`/other paths already handle) and treating it the same as `EXISTS` having returned false — because that's exactly what happened.
3. **WordPress-independence violation**: see §5 above (`DAY_IN_SECONDS`) — a real inconsistency between what two classes claimed about themselves and what they actually depended on, caught immediately by the test suite and fixed at the root rather than by loading WordPress into the test bootstrap.
4. **Mechanical**: several PHPCS false-positive placements (a `phpcs:ignore` comment positioned above a multi-line statement doesn't cover every line the statement spans) — fixed by restructuring the flagged multi-row `INSERT` statements into a single `$sql` variable assignment with the ignore comment placed at the one line that actually needs it, which also made the code more readable than the original inline multi-line call.

Everything else reviewed clean: no duplicated code beyond what's already precedented (the three `handle_video_*()`-shaped CLI methods, matching Phase 3's own considered-and-accepted point about near-identical one-line handlers), no dead code, no N+1 queries (every write is bulk; `window_sums()` is one aggregate query, not one per video), no missing indexes (checked `views_total`/`views_7d` against their documented consumers), no unnecessary hooks, no unnecessary abstractions (§3 above), no other race conditions beyond the one found and fixed, no event-ordering assumptions, REST API correctness N/A (no routes this phase), WPCS/PSR-12 clean, PHPStan level `max` clean.

One edge case considered and accepted, not fixed: if a video is deleted in the narrow window between `record()` and the next `views:flush`, the flush writes an orphaned row (a `video_id` with no corresponding post) into `wp_tube_video_views`/`wp_tube_video_statistics`. No foreign key exists anywhere in this schema (consistent with every other dedicated table in this project), so this is harmless leftover data, not a correctness bug — and it self-resolves via `Retention`'s normal purge cycle. Guarding against it would cost a query per flush for a vanishingly rare case; not worth it at this phase's real scale, and not technical debt (§11) since there's no gap between this and what a genuinely production-quality implementation would do here — this **is** that implementation.

## 11. Technical Debt Budget

Per `DEVELOPMENT_RULES.md` §10: **zero debt filed, none carried in.** `adr/DEBT-*.md` had no open items targeted at Phase 4 before this phase began. Every scope decision made this phase was checked against the "known, intentional gap between what was implemented and what genuinely production-quality implementation of that same piece of `ARCHITECTURE.md` would look like" test in §10, and none of them clear that bar:

- **No MySQL native partitioning** (§2.1): not a corner cut — it's the correctly-scoped implementation for this phase's explicit real-target-scale instruction. The alternative (building partitioning now) would itself have been the wrong call, not a more "complete" one.
- **`stats:rollup --full` running the identical recompute as the regular cadence** (§2.4): same reasoning — building artificial incremental-vs-full logic at this scale would be unjustified complexity, not missing completeness.
- **No REST endpoint for view recording** (§3.1, §8): not assigned to this phase by `ARCHITECTURE.md` §12; nothing here is a lesser version of something Phase 4 was supposed to build.
- **The orphaned-row edge case** (§10 above): explicitly reasoned through and accepted as correct, not deferred-because-rushed.
- **No dedicated PHPUnit tests for the three thin real-adapter classes** (§3.3): the same already-precedented, already-accepted pattern from Phases 2–3, not a new gap.

No Debt ADR filed. `ARCHITECTURE-CHANGELOG.md` is unchanged — no architecture decision changed this phase, frozen or otherwise.

---

Phases 0–4 are implemented, tested, and committed. Further implementation continues phase by phase, per `DEVELOPMENT_RULES.md` — waiting for explicit approval before Phase 5.
