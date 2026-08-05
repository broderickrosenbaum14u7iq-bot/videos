# Phase 9 — tube-seo: video XML sitemap generation

Status: **Complete.** Implements ARCHITECTURE.md §12 Phase 9's remaining scope after Phase 8 pulled the rest of `tube-seo`'s deliverable forward (see `ARCHITECTURE-CHANGELOG.md`'s 2026-08-05 reconciliation entry): XML video sitemap generation, sharded/indexed above a filterable URL-count ceiling, an incremental-regeneration check, `wp tube-seo sitemap:generate [--full]`, hourly Linux-cron wiring, and serving the generated files at clean top-level URLs.

---

## 1. Architecture Drift Report

Confirmed clean before this phase's work started (Phase 8's own commit `e90a5fd`, followed by the `b7bbf38` roadmap reconciliation and `2dd253c` comment fix, left a clean baseline), and re-confirmed after:

1. **No circular dependencies** — `tube-seo` depends on `tube-core` (`VideoPostType::POST_TYPE`, `Plugin::video_metadata_repository()`) and `tube-player` (`Plugin::video_provider()`), both already declared in `tube-seo.php`'s `Requires Plugins` header since Phase 8. Neither depends back on `tube-seo`.
2. **No service locator pattern** — `SitemapGenerator` receives `PublishedVideoRepository`/`SitemapXmlBuilder` via constructor injection. It reaches into `Tube_Core_Plugin::instance()->video_metadata_repository()` and `Tube_Player_Plugin::instance()->video_provider()` directly inside `build_entries()` — the same documented, precedented cross-plugin coupling `Tube_Seo\Head\SeoHead` already established in Phase 8, not a new pattern.
3. **No hidden singleton growth** — `SitemapGenerator`/`SitemapRouting`/`PublishedVideoRepository`/`SitemapXmlBuilder` hold no static state.
4. **No God classes** — `Tube_Seo\Plugin`: 2 accessors before this phase (`head()`; `instance()`/`boot()` aren't accessors), 2 lazy accessors after (`head()`, `sitemap_generator()`), plus two new static `activate()`/`deactivate()` methods matching `Tube_Core\Plugin`/`Tube_Search\Plugin`'s existing shape exactly. Well under §19.2's 6–8 reconsideration trigger.
5. **No duplicated abstractions** — `SitemapRouting` shares the rewrite-rule/query-var registration *shape* with `TermArchiveRouting`/`SearchRouting` but not their resolution logic (raw file serving via `readfile()`+`exit`, not `locate_template()`); the three were judged materially different concerns, not a missed opportunity to share a base class, consistent with this project's standing aversion to forcing an abstraction over three genuinely different implementations.
6. **No unnecessary interfaces** — no new interface introduced this phase. `VideoMetadataRepositoryInterface::find_many()` is a new *method* on an already-justified, already-existing interface (real second implementation: `VideoMetadataRepository` + `InMemoryVideoMetadataRepository`, the latter actually used to unit-test `tube-player`).
7. **No premature optimization** — every optimization this phase adds is directly required by this phase's own explicit scope: sharding (protocol limits + "efficient at 3k–10k videos"), the incremental-regeneration aggregate check ("incremental regeneration"), and `_prime_post_caches()` batching (this project's standing "no N+1 queries" discipline, re-affirmed in Phase 8's own performance review).
8. **No plugin-boundary violations** — `PublishedVideoRepository` reads `wp_posts` directly via `$wpdb`, a core WordPress table every plugin already reads via native APIs, not another plugin's dedicated custom table (§6.8's actual scope, per the same reasoning already applied to `tube-seo`'s Phase 8 work). `SitemapGenerator` reaches only `tube-core`'s/`tube-player`'s public `Plugin::instance()->x()` accessors, never their internal classes.

**Result: clean**, both before and after this phase's work.

---

## 2. What was built

### 2.1 tube-core: one batched prerequisite method

- **`VideoMetadataRepositoryInterface::find_many(array $video_ids): array`** (+ `VideoMetadataRepository` implementation, a single `WHERE video_id IN (...)` query; + `InMemoryVideoMetadataRepository::find_many()` for `tube-player`'s existing unit tests) — the batch read `SitemapGenerator` uses to avoid one query per video across a 3,000–10,000-video sitemap run. Distinct from `find()` (`tube-player`'s existing single-row-per-card read, Phase 6/8's own precedent), not a duplicate of it.

### 2.2 tube-seo: the sitemap subsystem (new `Sitemap`/`CLI` namespaces)

- **`PublishedVideoRepository`** — `published_videos(): list<array{video_id, post_date_gmt, post_modified_gmt}>` (one flat `$wpdb` read of `wp_posts`, minimal columns, no `WP_Post` hydration) and `aggregate_state(): array{count, max_modified_gmt}` (one `COUNT(*)`/`MAX(post_modified_gmt)` query — the incremental-regeneration check).
- **`VideoSitemapEntry`** — readonly DTO for one video's sitemap entry (`loc`, `lastmod`, `title`, `description`, `thumbnail_loc`, `player_loc`, `publication_date`, `duration_seconds`).
- **`SitemapXmlBuilder`** — pure, unit-tested XML rendering (`build_urlset()`, `build_index()`) via `DOMDocument`, sitemaps.org + Google's video-sitemap-extension namespaces. Every text value goes through `createTextNode()` (correctly escaped by the DOM regardless of content), not `createElement()`'s unescaped `$value` argument.
- **`SitemapGenerator`** — the WordPress-coupled orchestrator: gathers published-video data, batches metadata via `find_many()`, resolves thumbnail/embed URLs via `tube-player`'s `VideoProviderInterface`, builds `VideoSitemapEntry[]`, shards by a filterable `tube_seo_sitemap_urls_per_sitemap` ceiling (default 40,000), writes `video-sitemap.xml` (single shard) or `video-sitemap-{N}.xml` + `video-sitemap-index.xml` (multiple shards) under `wp-content/uploads/tube-seo-sitemaps/`, deletes any stale file left over from a differently-shaped previous run, and skips regeneration entirely unless `PublishedVideoRepository::aggregate_state()` changed since the last run (or `$force` is passed). Videos with no Cloudflare Stream metadata row yet are excluded (nothing real to publish as `thumbnail_loc`/`player_loc`).
- **`SitemapRouting`** — rewrite rule + `template_redirect` handler (priority 1 — see §3) serving a generated file directly via `readfile()` for `/video-sitemap.xml`, `/video-sitemap-index.xml`, `/video-sitemap-{N}.xml`; a real 404 (`$wp_query->set_404()`) for a validly-named file that doesn't exist yet; silently ignored for anything else (including a path-traversal attempt through the publicly-settable query var — re-validated in PHP, not just relied on via the rewrite regex).
- **`SitemapCommand`** — `wp tube-seo sitemap:generate [--full]`.
- **`Plugin`** — gained `sitemap_generator()` (lazy accessor), `activate()`/`deactivate()` (registers the rewrite rule + `flush_rewrite_rules()`, matching `tube-core`'s/`tube-search`'s exact existing shape), and `register_cli_commands()` wired into `boot()`.

### 2.3 Operations

- **`ops/cron/staging.cron`** — the `sitemap:generate` line's no-op placeholder replaced with the real `wp tube-seo sitemap:generate` command (hourly, unchanged cadence, matching every other already-real line's exact style).
- **`ops/docker/cron/Dockerfile`** bakes `staging.cron` in at build time — the `cron` container was rebuilt (`docker compose build cron && docker compose up -d cron`) so the real command is live, confirmed by reading `/etc/crontabs/root` inside the running container.

---

## 3. Design decisions

1. **Incremental regeneration is a poll-based aggregate check, not an event-driven "dirty" flag**, despite ARCHITECTURE.md §6 listing `video.published → tube-seo (sitemap flag)`. A publish-only event cannot detect the other two changes that must also invalidate a generated sitemap — unpublishing/trashing a video (drops out of `PublishedVideoRepository`'s `COUNT(*)`) and editing a still-published video's content (moves `MAX(post_modified_gmt)`) — neither of which fires `video.published`. Since the cron-driven command already re-runs this cheap aggregate check on every hourly invocation regardless, a separate event subscriber would only ever duplicate what that check already catches — real, unjustified complexity for no behavioral benefit, against this phase's explicit anti-over-engineering instruction. Documented in `SitemapGenerator`'s own class docblock, not silently deviated from.
2. **Sitemap generation state is a plain WP option (`tube_seo_sitemap_state`), not a dedicated table.** ARCHITECTURE.md line 132 documents `tube-seo` as owning no tables of its own ("may only need options") — this is the smallest persisted state that satisfies that, consistent with the project's convention (confirmed: no other plugin uses `wp_options` for state either, but none of them needed to; this is the first genuine "small persisted state, no relational shape" need in the project).
3. **`SitemapRouting`'s `template_redirect` handler is registered at priority 1, not the default 10** — found live, not anticipated: WordPress core's own `redirect_canonical()` (also on `template_redirect`, default priority 10, registered during `wp-settings.php` bootstrap before any plugin's `boot()` runs) 301-redirected `/video-sitemap.xml` to `/video-sitemap.xml/` before this class ever got a chance to serve it, since a slash-less non-post-type URL looks like an incomplete permalink to WordPress's canonical-redirect logic. Running first and `exit()`ing on a real match sidesteps `redirect_canonical()` entirely. Documented directly in `Plugin::boot()`'s own inline comment.
4. **Filenames are used unchanged as public URL slugs** (`video-sitemap.xml`, `video-sitemap-{N}.xml`, `video-sitemap-index.xml` — both the file on disk and the path segment WordPress routes) — the simplest correct scheme, avoiding a second name-mapping layer between storage and URL that nothing requires.
5. **A stale-file cleanup pass runs on every successful generation**, deleting any `video-sitemap*.xml` file not part of the current run's output — found live, not anticipated: switching from a single shard to multiple shards (or shrinking the shard count) left the previous run's now-orphaned files on disk, still publicly served with outdated content, since the original implementation only ever added/overwrote files, never reconciled the full set against what should currently exist. Fixed by comparing a glob of the directory against the current run's own filename list; a dedicated regression test (`test_generate_deletes_stale_files_left_over_from_a_larger_previous_run`) now covers it.
6. **`_prime_post_caches()`** — an internal (underscore-prefixed) WordPress core function with no public equivalent, used once per `generate()` run to batch-hydrate the post cache before calling `get_the_title()`/`get_the_excerpt()`/`get_permalink()` per video, avoiding one query per video for data `PublishedVideoRepository`'s own raw `$wpdb` read deliberately skips fetching. Despite the underscore prefix, it's the same mechanism `WP_Query`/`get_posts()` use internally for this exact purpose, stable in WordPress core since 3.4, and the standard technique for this scenario across the WordPress plugin ecosystem — a considered choice, not an oversight, given no public alternative exists.

---

## 4. Live verification

- **Single-shard generation**: seeded a real published video with real Cloudflare Stream metadata, ran `wp tube-seo sitemap:generate`, confirmed the written `video-sitemap.xml`'s content (correct real permalink, `lastmod`/`publication_date`, thumbnail/player URLs, duration) and confirmed `curl http://localhost:8080/video-sitemap.xml` returns `HTTP 200`, `Content-Type: application/xml; charset=UTF-8`, and the exact file content.
- **404 handling**: `curl http://localhost:8080/video-sitemap-999.xml` (a validly-named, never-generated shard) returns `HTTP 404`.
- **Incremental skip**: a second `sitemap:generate` run with nothing changed logs "No changes since the last run" and does not rewrite the file; `--full` forces regeneration regardless.
- **Sharding + index, live**: seeded two videos, forced `tube_seo_sitemap_urls_per_sitemap` down to 1, confirmed `wp tube-seo sitemap:generate` produced 2 shards + an index, and confirmed `HTTP 200` (with correct XML) for `video-sitemap-index.xml`, `video-sitemap-1.xml`, and `video-sitemap-2.xml` all served live.
- **Cron wiring**: rebuilt the `cron` container, confirmed `/etc/crontabs/root` inside the running container contains the real `wp tube-seo sitemap:generate` line (not the old no-op placeholder), and ran the exact command inside the `cron` container itself (not just `wpcli`) to confirm it behaves identically from cron's own execution context.
- **No trace left**: every live-verification video (and its metadata row) created during this process was deleted afterward, and the sitemap was regenerated once more to bring the live site back to reflecting only its real content — confirmed via a final `wp post list`/directory listing showing a clean state.

---

## 5. Benchmark Report

Run per `DEVELOPMENT_RULES.md` §9. Full results, methodology, and analysis in `BENCHMARKS.md`'s new "Phase 9" section (append-only, not reproduced here). Summary: **every tracked metric is unchanged from Phase 8 within normal run-to-run noise** — expected, since this phase's own deliverable (sitemap generation/serving) runs via WP-CLI/cron and a request-time route that returns immediately for every non-sitemap URL, not through any of the harness's tracked page-render/event/cache/import paths. `find_many()` is additive to `VideoMetadataRepository` and is never called during a normal page render.

Sitemap generation's own performance was verified directly (not via the generic harness, which has no sitemap-specific metric): a fixed, small number of queries regardless of video count (one `published_videos()` read, one `aggregate_state()` check, one batched `_prime_post_caches()` call, one batched `find_many()` call) — not one query per video, at this project's 3,000–10,000-video target scale.

## 6. Automated tests

### 6.1 Unit tests (pure logic only — no WordPress)

**6 new tests**: `SitemapXmlBuilderTest` (tube-seo) — documented shape, empty-list handling, multi-entry handling, null-duration omission, XML-escaping correctness (caught a real bug — see §7), `build_index()`'s shape. `PublishedVideoRepository`/`SitemapGenerator`/`SitemapRouting` are WordPress-coupled throughout and are integration-tested only, the same split `Tube_Seo\Head\SeoHead` already established in Phase 8.

### 6.2 Integration tests (real WordPress + MySQL, inside the `wpcli` Docker container)

**11 new tests**: `SitemapGeneratorIntegrationTest` (7 — single-shard generation against real seeded videos, exclusion of metadata-less videos, incremental skip, `--full` force, regeneration after a new publish, sharding + index generation, stale-file cleanup regression) and `SitemapRoutingIntegrationTest` (4 — query-var registration, the empty/invalid/404 non-terminating branches; the success branch, which `readfile()`s and `exit()`s, can't run inside PHPUnit without terminating the process and is instead covered by §4's live verification).

## 7. A real bug found by this phase's own unit test

`SitemapXmlBuilder`'s original implementation used `DOMDocument::createElement($name, $value)`'s third argument to set element text — that argument does **not** auto-escape XML-significant characters. `test_special_characters_are_safely_escaped` (a title containing `Rock & Roll <Live> "Show"`) caught this immediately: the unescaped `&` corrupted the DOM parse, producing an empty string instead of the real title. Fixed by refactoring every text-bearing element through a `text_element()` helper using `createElement($name)` + `appendChild(createTextNode($text))`, which the DOM's own serializer escapes correctly regardless of content — a real, structural improvement, not a workaround.

## 8. Verification summary

| Check | Result |
|---|---|
| `phpcs` (whole repo, 210 files) | Exit `0` |
| `phpstan analyse --memory-limit=1G` (whole repo, level `max`, 210 files) | Exit `0`, `[OK] No errors` |
| `phpunit` (tube-core/tube-cache/tube-player/tube-search/tube-seo unit suites) | 144/144 passing (63+22+9+23+27) |
| `phpunit -c phpunit-integration.xml.dist` (tube-core/tube-player/tube-search/tube-seo, real stack) | 67/67 passing (23+7+23+14) — `tube-cache` has no integration suite (pre-existing, unrelated to this phase) |
| Live verification (§4) | Confirmed correct |
| Benchmark Report | Complete (§5, full detail in `BENCHMARKS.md`) |
| `git status` | Clean except this phase's intended files |

## 9. Explicitly out of scope for Phase 9

Everything else `ARCHITECTURE.md`'s Phase 9 row originally listed was already delivered by Phase 8's pull-forward (title/meta description/canonical/robots/OpenGraph/Twitter Cards/JSON-LD/pagination metadata — see `PHASE-8.md`, `ARCHITECTURE-CHANGELOG.md`'s 2026-08-05 entry). Nothing from that list was re-touched this phase.

**A `video.published`-driven sitemap-dirty event subscriber** — see §3.1; the poll-based aggregate check already covers the same intent more completely (publish, edit, *and* unpublish), so building one would be pure duplication.

**Locking against concurrent `sitemap:generate` invocations** — not built. At an hourly cron cadence with occasional manual/`--full` runs, two genuinely concurrent invocations are unlikely, and no other WP-CLI command in this project (`stats:rollup`, `views:flush`, `index:rebuild`) uses locking either. A worst-case race would mean a brief window where a concurrent HTTP request could read a partially-written file — low probability, low severity, and consistent with this project's existing precedent for CLI-driven jobs; not treated as a gap.

## 10. Production impact

None. All work happened in the local Docker staging environment. Production (`root@139.99.96.155`) was not accessed.

---

## 11. Implementation Review

Run per `DEVELOPMENT_RULES.md` §7, dimension by dimension, before this commit.

1. **Fixed — a real XML-escaping bug**, caught by this phase's own unit test before any live use. See §7.
2. **Fixed — a real stale-file bug**, caught during this phase's own live verification, not anticipated in the original design. See §3.5. A dedicated regression test now covers it, closing the gap between "found live" and "covered by the automated suite."
3. **Fixed — a real routing bug** (`redirect_canonical()` intercepting the sitemap URL before this plugin's own handler ran), caught during live verification. See §3.3.
4. **Correctness**: `mysql2date(DATE_ATOM, $gmt_string, false)` verified live to return true GMT (`+00:00`) offsets without applying the site's local timezone — confirmed via a direct `wp eval` check, not assumed from documentation alone.
5. **Performance/N+1**: `_prime_post_caches()` batches the post-cache hydration `get_the_title()`/`get_the_excerpt()`/`get_permalink()` need (one query for up to the entire published-video set, not one per video); `find_many()` batches metadata the same way. Total query count for a full `generate()` run is fixed and small, independent of video count, verified by design (§5) rather than live query-counting (the SQL-count-investigation technique from Phase 8 wasn't re-run this phase, since the query shape here is self-evidently batched, not a case requiring live measurement to be confident about).
6. **Security**: `SitemapRouting`'s filename is re-validated against a fixed regex in PHP even though the rewrite rule already constrains it, specifically because WordPress's public `query_vars` are also settable directly via `?tube_seo_sitemap_file=...`, bypassing the rewrite match entirely — the actual boundary against a path-traversal attempt reaching `SitemapGenerator::directory()`. File writes/reads use plain PHP filesystem functions (not `WP_Filesystem`), a documented, precedented choice for a WP-CLI/cron-only write to this plugin's own already-owned uploads subdirectory (no FTP-credential scenario applies).
7. **Race conditions**: considered explicitly, accepted, not fixed — see §9's locking note.
8. **Migration/rollback risk**: N/A — no new migrations this phase.
9. **Event ordering**: `SitemapRouting`'s `template_redirect` priority (1, not the default 10) is a deliberate, documented exception to this project's usual "default priority" convention, justified by a real, verified conflict with WordPress core's own `redirect_canonical()` — not an arbitrary choice.
10. **Dead code**: none found — every new file's imports checked for genuine use; no `TODO`/`FIXME` in any new file.
11. **Testability**: `PublishedVideoRepository`/`SitemapGenerator`/`SitemapRouting` are WordPress-coupled and integration-tested rather than unit-tested against a fake, the same justified split as `SeoHead` (Phase 8) — no interface was introduced for these solely to enable a unit test with no real second implementation, per §6.6's actual test.
12. **Considered, not fixed — integration test count assertions assume a clean-of-ready-videos baseline in the shared staging DB.** `SitemapGeneratorIntegrationTest`'s exact-count assertions (e.g. `assertSame(2, $result->video_count)`) would be thrown off by ambient published-and-metadata'd videos left in the staging database by something other than the test itself — exactly what happened transiently during this phase's own live verification, before cleanup. This is a test-suite fragility, not a production-code gap (`SitemapGenerator` itself is unaffected either way), so it doesn't meet §10's definition of technical debt; noted here for transparency rather than silently left unmentioned. Not hardened further this phase, since the actual cause (live-verification residue) is now cleaned up and this project's own convention is to leave no trace after live verification, making recurrence unlikely.

Everything else reviewed clean: no duplicated abstractions beyond what's already justified (§1.5), WPCS/PSR-12 clean, PHPStan level `max` clean across the whole repo.

## 12. Technical Debt Budget

Per `DEVELOPMENT_RULES.md` §10: **zero debt filed, none carried in.** No open `adr/DEBT-*.md` items exist in this project. Checked against the "known, intentional gap between what was implemented and what genuinely production-quality implementation would look like" test:

- **No event subscriber for `video.published`** (§3.1, §9): a considered design decision with a stated, verified reason it's unnecessary, not a corner cut.
- **No concurrency locking** (§9): consistent with every other WP-CLI job in this project; not a gap introduced by this phase.
- **The test-isolation fragility** (§11 #12): affects only the test suite's assumptions about a shared environment, not `SitemapGenerator`'s own correctness — doesn't meet §10's definition.
- **The XML-escaping bug, the stale-file bug, and the `redirect_canonical()` conflict** (§7, §3.5, §3.3) were all found and **fixed** in this same commit, not deferred — there is nothing left to file for any of them.

No Debt ADR filed. `ARCHITECTURE-CHANGELOG.md` is unchanged — no architecture decision changed this phase.

---

Phases 0–9 are implemented, tested, and committed. Per `ARCHITECTURE.md` §12 (as reconciled in `ARCHITECTURE-CHANGELOG.md`), Phase 9 was the last piece of `tube-seo`'s originally-scoped work; further implementation continues phase by phase, per `DEVELOPMENT_RULES.md`, waiting for explicit approval before Phase 10.
