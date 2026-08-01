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
