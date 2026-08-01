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
