# Benchmarks

Durable, append-only record of every phase's Benchmark Report (`DEVELOPMENT_RULES.md` §9). Never edit a prior phase's numbers — if a regression is found and fixed, that shows up as a *better* number in the next phase's own section, not as an edit to history. Each section's methodology is `ops/benchmark/run.sh`, run against the local Docker staging environment with the stack already warm (not a cold start).

---

## Phase 2 baseline (retroactive)

Measured 2026-08-01, after Phase 2 was already committed and the Architecture Freeze completed — this is the first Benchmark Report this project has produced, so it's necessarily retroactive; there is no Phase 1 report to compare it against. Phase 3's report is the first one with a real prior-phase comparison.

Three consecutive runs of `ops/benchmark/run.sh` were taken to distinguish real signal from run-to-run noise, per §9's "a 3% timing jitter is noise, not a regression" rule. Deterministic metrics (memory, query count) were identical across all three runs; wall-clock timings show the normal jitter of a warm PHP-FPM/Docker environment, with the first run of a batch consistently slower (cold opcache/filesystem cache) — reported as a range, not a single misleadingly-precise number.

| Metric | Result | Notes |
|---|---|---|
| PHP memory usage | **49.313 MB** peak, identical across all 3 runs | `MigrationRunner::status()`, via `memory_get_peak_usage(true)` |
| Execution time | **0.53–1.02 ms** (0.53–0.60 ms steady-state, 1.02 ms on the cold first run) | Same operation, via `microtime(true)` around the call only — excludes WordPress/wp-cli bootstrap |
| SQL query count | **1 query**, identical across all 3 runs | `$wpdb->num_queries` delta around the same operation — one query to `wp_tube_schema_versions` per `status()` call |
| Cache hits | N/A | No cache layer exists yet (`tube-cache` is Phase 3) |
| Cache misses | N/A | Same as above |
| REST latency | **9.3–17.0 ms** (9.3–11.3 ms steady-state, 17.0 ms on the cold first run) | `GET /wp-json/wp/v2/videos` (WordPress core's own endpoint — no `tube/v1` custom routes exist yet; this is a baseline for the *stack*, not for tube-core's future REST layer) |
| Page generation time | **6.6–8.3 ms** (`/watch/test-video-one/`), **6.7–7.9 ms** (`/`) | Both against the Phase 1 fallback template (`tube-theme`'s placeholder `index.php`) — not a real theme yet (Phase 8) |
| Import throughput | N/A | No import pipeline exists yet (Phase 5) |
| Event dispatch cost | **0.20–0.26 ms total for 1,000 dispatches** (≈0.0002–0.00026 ms per dispatch), identical order of magnitude across all 3 runs | `Dispatcher::dispatch(VIDEO_UPDATED)` through the real `Dispatcher` + `WordPressHookBus`, no listeners registered yet (nothing subscribes as of Phase 2) — this measures the dispatcher's own catalog-validation + `do_action()` overhead only |

### Reading these numbers

- **1 SQL query for `MigrationRunner::status()`** is already optimal for what the operation does (a single indexed lookup against `wp_tube_schema_versions`, per plugin, per Phase 1's design) — there is no N+1 pattern here even though `status()` iterates 4 migrations, because `applied_versions()` is called once per *plugin*, not once per *migration* (confirmed by the query count, not assumed from reading the code).
- **Event dispatch cost is negligible** (sub-microsecond per call) — this is the number to compare against once real listeners exist from Phase 3 onward; a large jump here in a future phase would mean a *listener* got expensive, not the dispatcher itself, since this benchmark isolates the dispatcher from any listener.
- **REST/page timings in the single-digit-to-low-double-digit milliseconds** are expected and unremarkable at this stage — there is effectively no application logic on these paths yet (no real query layer, no real theme, no real REST controllers). These numbers exist as a *stack* baseline (Docker networking + nginx + PHP-FPM + WordPress bootstrap), not as a measurement of any tube-core-specific code — later phases adding real logic to these paths are expected to move these numbers up, and the point of comparing against the previous phase is to confirm any increase is proportionate to what was actually added, not a sign of an accidental inefficiency.

---

## Phase 3 (tube-cache)

Measured 2026-08-02 against the local Docker staging environment, stack already warm, immediately after Phase 3's Implementation Review and before its commit. Three consecutive runs of `ops/benchmark/run.sh`, same methodology as the Phase 2 baseline (deterministic metrics compared exactly; wall-clock timings reported as a range, first run treated as the cold one).

| Metric | Result | Notes |
|---|---|---|
| PHP memory usage | **51.313 MB** peak, identical across all 3 runs | Same operation as Phase 2 (`MigrationRunner::status()`) — the **+2.0 MB** over Phase 2's 49.313 MB is explained below, not a regression in this operation |
| Execution time | **0.538–0.915 ms** | Same operation, same range as Phase 2's 0.53–1.02 ms — unaffected, as expected: tube-cache touches nothing this operation calls |
| SQL query count | **1 query**, identical across all 3 runs | Unchanged from Phase 2 — tube-cache has no MySQL tables (`ARCHITECTURE.md` §4), so there is nothing here for it to add a query to |
| Cache hits | **1,000** (Redis `INFO stats` → `keyspace_hits`) | First real number this project has produced for this row — see methodology below |
| Cache misses | **1,000** (Redis `INFO stats` → `keyspace_misses`) | Same as above |
| REST latency | **8.96–157.64 ms** (8.96–10.26 ms steady-state, 157.64 ms on the cold first run) | Same endpoint as Phase 2, same steady-state range as Phase 2's 9.3–17.0 ms — the cold-run outlier is larger than Phase 2's (17.0 ms) because this was the first request after `docker compose up -d --force-recreate` picked up the new Redis env vars (§ note below), not a tube-cache regression |
| Page generation time | **6.63–7.29 ms** (`/watch/test-video-one/`), **6.63–7.25 ms** (`/`) | Same range as Phase 2 (6.6–8.3 ms / 6.7–7.9 ms) — unaffected, as expected: no theme or template code touches tube-cache yet (Phase 8) |
| Import throughput | N/A | Still no import pipeline (Phase 5) |
| Event dispatch cost | **29.18–30.08 ms total for 1,000 dispatches** (≈0.029–0.030 ms per dispatch) | **Up from Phase 2's ≈0.0002 ms per dispatch — expected, not a regression; see below** |

### The event dispatch number, explained

Phase 2's own `BENCHMARKS.md` entry predicted exactly this: *"a large jump here in a future phase would mean a listener got expensive, not the dispatcher itself, since this benchmark isolates the dispatcher from any listener."* That prediction is what happened. The benchmark dispatches `EventCatalog::VIDEO_UPDATED` — and as of this phase, `Tube_Cache\Events\CachePurgeSubscriber` is a real, live listener on that exact event (wired in `Plugin::boot()` on `plugins_loaded`). Each of the 1,000 benchmark dispatches now triggers a real, synchronous Redis `DEL` command over the Docker network, not just the dispatcher's own `in_array` + `do_action` overhead Phase 2 measured in isolation.

**≈0.03 ms per dispatch, including a real Redis round-trip, is an excellent number** — not a performance problem. The right comparison isn't against Phase 2's isolated-dispatcher figure (that number no longer describes what actually happens on this event, now that a real subscriber exists) but against the cache-operation cost measured directly below, which shows the same ≈0.03 ms is simply what one Redis command costs on this stack. There is no dispatcher regression: `event-dispatch.php`'s own logic is untouched by Phase 3.

### Cache hits/misses methodology

`ops/benchmark/cache-operations.php` (new this phase) exercises the real `Tube_Cache\Plugin::cache()` API — 1,000 `set()` calls, 1,000 `get()` calls against those same keys (real hits), 1,000 `get()` calls against keys that were never set (real misses), then cleans up everything it wrote. `run.sh` resets Redis's stat counters (`CONFIG RESETSTAT`) immediately before running it, so the `INFO stats` delta read afterward is exactly this benchmark's own activity, not accumulated staging traffic. The 1,000/1,000 result confirms the instrumentation is accurate (it could not be any other number, by construction) and, as a bonus data point, the script's own timing shows each operation costs ≈0.027–0.030 ms — `set()`, a cache hit, and a cache miss are all roughly the same cost, as expected for a single round-trip to a local Redis instance.

### PHP memory, explained

The **+2.0 MB** peak-memory increase (49.313 → 51.313 MB) on an operation tube-cache doesn't touch is Predis's own class footprint being autoloaded into the same PHP-FPM worker now that `tube-cache` is an active plugin with a real `vendor/autoload.php` — expected, proportionate (Predis is a substantial library), and not specific to `MigrationRunner::status()` itself, which is why the query count and execution time for that same operation are unchanged.

---

## Phase 4 (tube-core: video_views/video_statistics)

Measured 2026-08-02 against the local Docker staging environment, stack already warm, immediately after Phase 4's Implementation Review and before its commit. Three consecutive runs of `ops/benchmark/run.sh`, same methodology as Phases 2–3.

No new benchmark script this phase — unlike Phase 3 (which added `cache-operations.php` to fill in the previously-N/A cache hits/misses row), Phase 4 doesn't turn any of the 9 tracked metrics from N/A into measurable; the same three existing scripts already cover everything in scope. `views:flush`/`stats:rollup`/`views:partition-maintenance`'s own cost is not one of the 9 tracked metrics and was verified functionally correct (§6 of `PHASE-4.md`) rather than benchmarked — nothing in `DEVELOPMENT_RULES.md` §9's table calls for a dedicated row for it, and adding one un-asked-for would be exactly the kind of scope creep this phase's "keep implementation simple" instruction rules out.

| Metric | Result | Notes |
|---|---|---|
| PHP memory usage | **51.313 MB** peak, identical across all 3 runs | Same operation as Phases 2–3 (`MigrationRunner::status()`) — **unchanged from Phase 3**. Predis is now also a tube-core dependency (for `RedisViewCounter`), but this benchmark's operation never touches `Tube_Core\Views\*`, so PHP's lazy autoloading never loads those classes here — expected, not a coincidence |
| Execution time | **0.745–1.128 ms** | Same range as Phase 3's 0.538–0.915 ms — unaffected, as expected |
| SQL query count | **1 query**, identical across all 3 runs | Unchanged — this operation doesn't touch the two new tables |
| Cache hits | **1,000** (Redis `INFO stats` → `keyspace_hits`) | Unchanged methodology and result from Phase 3 — tube-core's Redis usage (`tube_core:view_buffer`) is a separate key namespace `cache-operations.php` never touches, so this row still measures only `tube-cache`'s traffic |
| Cache misses | **1,000** (Redis `INFO stats` → `keyspace_misses`) | Same as above |
| REST latency | **9.08–13.90 ms** | Consistent with Phase 3's steady-state 8.96–10.26 ms range (no cold-start outlier this run — the stack was already warm going in) |
| Page generation time | **6.34–8.32 ms** (`/watch/test-video-one/`), **6.69–7.78 ms** (`/`) | Consistent with Phase 3's ranges — unaffected, as expected: no theme or template code touches the Views feature yet |
| Import throughput | N/A | Still no import pipeline (Phase 5) |
| Event dispatch cost | **29.06–30.17 ms total for 1,000 dispatches** (≈0.029–0.030 ms per dispatch) | Unchanged from Phase 3 — this benchmark dispatches `VIDEO_UPDATED`, which nothing in Phase 4 subscribes to; `VIDEO_VIEW_RECORDED`/`VIDEO_STATS_ROLLED_UP` (Phase 4's own events) have no subscribers yet either, so they weren't expected to move this number and didn't |

### Reading these numbers

Every metric is either unchanged or within normal run-to-run jitter of Phase 3's baseline — genuinely unaffected, not coincidentally so: Phase 4's entire surface (two new tables, `RedisViewCounter`'s own Redis key namespace, three new WP-CLI commands) is disjoint from every operation these benchmarks exercise. That disjointness was confirmed by reasoning about which code path each benchmark touches, not assumed — the same standard applied in Phases 2 and 3. The real evidence that Phase 4's own operations work and perform reasonably is the live verification in `PHASE-4.md` §6 (real `views:flush`/`stats:rollup`/`views:partition-maintenance` runs against real Redis and real MySQL, inspected directly), not a number in this table.

---

## Phase 5 (tube-core: import pipeline, Cloudflare Stream webhook, watch history)

Measured 2026-08-02 against the local Docker staging environment, stack already warm, immediately after Phase 5's Implementation Review and before its commit. Three consecutive runs of `ops/benchmark/run.sh`, same methodology as Phases 2–4.

**New benchmark script this phase**: `ops/benchmark/import-throughput.php`, wired into `run.sh` in place of the "Import throughput: N/A" line every prior phase's report carried — this is the first phase where that row has a real subsystem to measure. It enqueues 200 synthetic items under a unique `source_key` prefix, times one `BatchProcessor::process()` run against all of them through the real `ImportQueueRepository` + `VideoImporter` (real `wp_insert_post()`, real `wp_tube_video_metadata` writes), then deletes everything it created — the same "benchmark scripts leave no residue" discipline `cache-operations.php` established in Phase 3.

The first run of this report's REST/page-generation requests initially came back `502` — traced immediately to the `nginx` container holding a stale upstream IP for the `wordpress` container from an unrelated `docker compose up -d --force-recreate wordpress wpcli` earlier in this session (needed to pick up the newly-added `TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET` env var), not a Phase 5 regression — `docker compose restart nginx` resolved it, confirmed by re-running the full harness clean afterward. Only the results below (post-fix) are reported.

| Metric | Result | Notes |
|---|---|---|
| PHP memory usage | **51.313 MB** peak, identical across all 3 runs | Same operation as Phases 2–4 (`MigrationRunner::status()`) — **unchanged from Phase 4**. Phase 5 adds no new dependency this operation's code path autoloads |
| Execution time | **0.853–1.249 ms** | Same range as Phase 4's 0.745–1.128 ms — unaffected, as expected |
| SQL query count | **1 query**, identical across all 3 runs | Unchanged — this operation doesn't touch any of Phase 5's new tables |
| Cache hits | **1,000** (Redis `INFO stats` → `keyspace_hits`) | Unchanged methodology and result from Phases 3–4 — Phase 5's Redis usage is limited to the webhook secret's plain PHP constant (no Redis at all) and the existing `tube_core:view_buffer`/tube-cache namespaces this benchmark doesn't touch |
| Cache misses | **1,000** (Redis `INFO stats` → `keyspace_misses`) | Same as above |
| REST latency | **10.67–14.28 ms** (`GET /wp-json/wp/v2/videos`, WordPress core's own endpoint) | Consistent with Phase 4's 9.08–13.90 ms range — unaffected, as expected: this endpoint doesn't route through any `tube/v1` controller Phase 5 added |
| Page generation time | **6.62–7.48 ms** (`/watch/test-video-one/`), **7.08–7.37 ms** (`/`) | Consistent with Phase 4's ranges — unaffected, as expected: no theme/template code touches the import pipeline, webhook, or watch history yet |
| Import throughput | **1,093.58–1,197.67 items/second** (166.99–182.89 ms for 200 items), `completed: 200, retried: 0, failed: 0` on every run | **First real number for this row** — see methodology above. All 200 items succeed on every run (deterministic synthetic payloads with unique `cf_stream_uid`s, no forced-failure items in this benchmark) |
| Event dispatch cost | **28.83–30.80 ms total for 1,000 dispatches** (≈0.029–0.031 ms per dispatch) | Consistent with Phase 4's 29.06–30.17 ms range — unaffected, as expected: this benchmark still dispatches `VIDEO_UPDATED`, which nothing in Phase 5 subscribes to; `VIDEO_STREAM_STATUS_CHANGED`/`IMPORT_ITEM_COMPLETED`/`IMPORT_ITEM_FAILED` (Phase 5's own events) have no subscribers yet either |

### Reading these numbers

Every metric already tracked before this phase is either unchanged or within normal run-to-run jitter of Phase 4's baseline — Phase 5's entire surface (two new tables, one new REST controller pair, three new WP-CLI commands) is disjoint from every operation these benchmarks exercise, the same disjointness reasoning applied in every prior phase's report. **1,093–1,198 items/second sustained through the real, single-worker `import:process` pipeline is well beyond what this phase's real-scale target requires** — importing the entire stated ceiling of 10,000 videos in one `import:process` run would take roughly 8–9 seconds of pure processing time at this measured rate, against a design that runs continuously during an initial bulk backfill and every minute in steady state (`ARCHITECTURE.md` §7). This confirms `ImportQueueRepository::claim_batch()`'s "reclaim, then claim, then fetch" sequence (§2 of `PHASE-5.md`) and `VideoImporter`'s per-item `wp_insert_post()` + metadata-repository write are not a throughput bottleneck at this project's actual target scale, without needing to speculate about it.

The real evidence that Phase 5's Cloudflare Stream webhook and watch history endpoints work correctly is the live/integration verification in `PHASE-5.md` §6 (real signed HTTP-shaped requests through the actual REST server, real resume-after-interruption, real duplicate-detection at both the import-queue and watch-history layers), not a number in this table — neither of those two features has a dedicated row in `DEVELOPMENT_RULES.md` §9's tracked-metrics table, and adding one un-asked-for would be scope creep this phase's "keep implementation simple" instruction rules out, the same restraint Phase 4's report already exercised for its own CLI commands.
