# Monitoring Guide

Concrete monitoring targets for the confirmed production target (single VPS, 3,000–10,000 videos, Redis, MySQL, Cloudflare CDN), expanding `ARCHITECTURE.md` §18.5 into specific commands/thresholds. Alert thresholds below are starting points calibrated against this project's own `BENCHMARKS.md` baselines (most recently the Phase 12 section) — tune them against real production traffic once it exists, per §18.5's own note that specific thresholds are a launch-time task, not something to guess permanently.

## 1. Application

| What | How | Alert when |
|---|---|---|
| PHP fatal/error log | `WP_DEBUG_LOG` output (never `WP_DEBUG_DISPLAY` — `docs/DEPLOYMENT.md` §2) | Any fatal; a sustained rise in warning/notice volume vs. the normal baseline |
| `tube/v1` REST error rate/latency | Web server access log, filtered to `/wp-json/tube/v1/*` | Error rate `> 1%`; p95 latency materially above `BENCHMARKS.md`'s REST baseline (Phase 12: 13.63–14.26 ms for the comparable core REST endpoint) |
| Watch-history endpoint specifically | Same as above, this is the highest-frequency route (called by every playing video, not just every page load) | Same thresholds, watched first — it degrades before anything else would |
| Every Linux-cron job's exit code | `/var/log/tube-cron/*.log` (§5 of `docs/DEPLOYMENT.md`'s crontab) | Any non-zero exit; a job silently not running at all (see dead-man's-switch below) |
| `stats:rollup` dead-man's-switch | `wp tube-core stats:rollup` runs every 5 minutes — alert if `wp_tube_video_statistics.updated_at`'s most recent value is more than ~15 minutes old | Confirms the job is actually running, not just that its last invocation didn't error |
| `index:rebuild` freshness | Runs nightly (01:00) — alert if it hasn't completed successfully in > 25 hours | Search staleness is otherwise silent — nothing else would surface a stuck job |
| `sitemap:generate` freshness | Runs hourly — alert if it hasn't completed successfully in > 2 hours | Same reasoning as above, for sitemap staleness |

## 2. Infrastructure

| What | How | Alert when |
|---|---|---|
| Disk usage | Standard OS monitoring | `> 80%` — this project writes no video/image bytes locally, so disk growth should be slow and predictable (logs, sitemap XML files, `wp_tube_search_index`'s own growth); a sudden jump is itself worth investigating |
| PHP-FPM worker saturation | `pm.status_path` or process count vs. `pm.max_children` | Sustained `> 80%` worker utilization |
| MySQL slow-query log | `long_query_time` set low enough to catch a genuinely-missed index (the final SQL audit, `PHASE-12.md` §, confirmed every current query is indexed — a new slow query after launch means either a new query path or unexpected data skew) | Any query against a `wp_tube_*` table that isn't hitting an index — this should never happen at this project's real scale and is worth treating as a real bug report, not routine noise |
| Redis memory usage | `redis-cli INFO memory` | `> 80%` of configured `maxmemory` — Redis is load-bearing for the view-counter buffer (`RedisViewCounter`), not just a cache, so pressure here has a direct data-loss implication (an `OOM` response is already handled gracefully by `RedisCache`/`RedisViewCounter`'s Phase 11 exception-widening fix, but "handled gracefully" means "degraded," not "fine to ignore") |
| Redis eviction rate | `redis-cli INFO stats` → `evicted_keys` | Any sustained non-zero rate — evictions mean the cache is undersized for the working set, which silently increases MySQL load as cache misses rise |

## 3. Business/content

| What | How | Alert when |
|---|---|---|
| `wp_tube_import_queue` depth | `wp tube-core import:status` | A growing (not draining) backlog over time — the queue is processed every minute by cron; steady growth means the import rate exceeds processing capacity |
| Import failure rate | Same command, or `tube-admin`'s Import Dashboard (`ImportFailureNotice` already surfaces permanently-failed items as a real-time `wp-admin` notice) | Any sustained rate of permanently-failed items, not just the occasional transient retry |
| Time since last successful `index:rebuild` | Covered above under Application | (see above) |

## 4. What NOT to alert on (documented so it isn't mistaken for a gap)

- **Redis data loss** — a Redis restart/crash loses at most the current flush interval's buffered view counts (bounded, accepted per `ARCHITECTURE_FREEZE.md`'s Known Tradeoffs). Alert on Redis being *down* (a real infrastructure event), not on the bounded data-loss implication of a restart, which is by design.
- **`wp_tube_search_index` being briefly stale** between `index:rebuild` runs — expected, bounded by the nightly cadence; only alert if a run is actually missed/failing (§1 above), not on the normal staleness window between successful runs.
- **A single cache miss** — `RedisCache`'s fail-open design means a Redis hiccup degrades individual requests to a cache miss (a real query instead), never an error. This is the intended behavior; only the aggregate signals above (eviction rate, memory pressure, Redis being fully down) are the real alerting surface.

## 5. Baseline reference

Every number above should be read against `BENCHMARKS.md`'s most recent section (Phase 12, the 1.0.0 release baseline) — that document is the reference point for "normal," not a number memorized here that will go stale. Re-baseline (a new `BENCHMARKS.md` entry) after any release that changes a benchmarked code path, and periodically against real production traffic once launched, since production load will differ from staging's synthetic benchmark harness.
