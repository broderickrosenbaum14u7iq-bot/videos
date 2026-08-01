# Phase 3 — Tube Cache

Status: **Complete.** Implements exactly `ARCHITECTURE.md` §12's Phase 3 scope: `tube-cache`'s Redis connection layer, caching API, rate-limit helper, and event subscriber for `video.published`/`video.updated`/`video.deleted` (purge). Also establishes PHPStan (level `max`) as a project-wide tool, required by `DEVELOPMENT_RULES.md` §2 and explicitly requested for this phase — the first phase where it's actually configured and enforced, rather than described as forthcoming.

---

## 1. Architecture Drift Report (before this phase's work started)

Run against the codebase exactly as Phase 2 left it, per `DEVELOPMENT_RULES.md` §6 (reduced scope while frozen). Re-checked fresh, not reused from a prior session.

1. **No circular dependencies** — `grep` for `Tube_Player`/`Tube_Search`/`Tube_Seo`/`Tube_Admin`/`Tube_Cache` inside `tube-core`'s `includes`/`migrations`: none found.
2. **No service locator pattern** — `Plugin::instance()` called only from `Plugin.php` itself and `tube-core.php`'s bootstrap closure: confirmed, no other call sites.
3. **No hidden singleton growth** — no `private static`/`public static $x` outside `Plugin.php`: confirmed.
4. **No God classes** — `Plugin.php` at 181 lines, all-thin accessors: confirmed.
5. **No duplicated abstractions** — nothing new to compare against; no change since Phase 2.
6. **No unnecessary interfaces** — `MigrationInterface`, `SchemaVersionRepositoryInterface`, `HookBusInterface` each have exactly one real implementation and one test fake, confirmed by `grep`.
7. **No premature optimization** — no change since Phase 2.
8. **No plugin boundary violations** — `grep` for `wp_tube_` inside every non-`tube-core` plugin: none found.

**Result: clean.** No drift found; Phase 3 started from an unmodified Phase 2 baseline.

---

## 2. What was built

### 2.1 PHPStan (level `max`), project-wide

Not part of `ARCHITECTURE.md` §12's phase table — required by `DEVELOPMENT_RULES.md` §2 ("Static analysis tool: PHPStan with WordPress stubs... at a level chosen once it's set up, as a small dev-tooling task, not mid-phase") and explicitly requested for this phase ("PHPStan level max configured for the project"). Treated as dev tooling, not architecture — no ADR needed, nothing in `ARCHITECTURE.md`/`ARCHITECTURE_FREEZE.md` changed.

- `phpstan.neon.dist` (repo root): same scan scope as `phpcs.xml` (`wp-content/plugins`, `wp-content/themes/tube-theme`), `level: max`, `szepeviktor/phpstan-wordpress` for WordPress core stubs, `php-stubs/wp-cli-stubs` for `WP_CLI`. Each plugin's own `vendor/autoload.php` is listed under `bootstrapFiles` (no shared root autoloader exists — every plugin is independently composer-installed, `ARCHITECTURE.md` §4/§19.4) — a line to add the phase a plugin first gets real PHP classes.
- Root `composer.json`: added `phpstan/phpstan` (`^2.1`), `szepeviktor/phpstan-wordpress` (`^2.0`), `php-stubs/wp-cli-stubs` (`^2.10`) as dev dependencies; `composer analyze` script runs it with `--memory-limit=1G` (the default 128M genuinely isn't enough at level `max` against this codebase + the WordPress/WP-CLI stub set — confirmed empirically, not assumed, the first run OOM'd at the default).
- **Existing tube-core code from Phases 1–2 was not previously level-`max`-clean** — this phase found and fixed real gaps, not stylistic ones:
  - `VideoPostType`/`CategoryTaxonomy`/`TagTaxonomy`'s private `args()` methods were typed `array<string, mixed>`; `register_post_type()`/`register_taxonomy()`'s stub signatures want a precise shape. Fixed by writing the exact `@return array{...}` shape each method actually returns — strictly more accurate documentation, not a workaround.
  - `global $wpdb;` typed as `mixed` everywhere it's used (`SchemaVersionStore`, `AbstractMigration::db()`) — `wordpress-stubs` declares `wpdb`'s methods but never types the global variable itself. Fixed with PHPStan's standard mechanism for this: an inline `/** @var wpdb $wpdb */` immediately after each `global $wpdb;` statement. This is providing a type the stubs genuinely don't supply, not overriding a correctly-inferred one.
  - `SchemaVersionStore::applied_versions()`'s SQL interpolated the table name into the query string (`"...FROM {$table} WHERE..."`) with a `phpcs:ignore` justifying it as identifier-only, never user input. PHPStan's `wpdb::prepare()` stub requires a `literal-string` `$query`, which a runtime-interpolated string can never satisfy regardless of how safe the interpolated value actually is. Fixed by switching to `%i` (WordPress 6.2+'s identifier placeholder), which parameterizes the table name through `prepare()` itself — genuinely more correct than the interpolation-plus-`phpcs:ignore` it replaces, not merely a way to satisfy the type checker. The now-unnecessary `phpcs:ignore` comment was removed.
  - `wpdb::get_results(..., ARRAY_A)`'s stub types its return as `array|object|null` regardless of `$output_type` (the stub can't discriminate on a runtime constant) — a real, documented gap in `wordpress-stubs`, not a bug in this project's code. Fixed with an inline `@var` supplying the shape this specific call actually produces (a fixed two-column `SELECT`), with a comment explaining why.
  - `Dispatcher::guard_known_event()` validates `$event` against `EventCatalog::all()` but didn't tell PHPStan that a normal return means `$event` is non-empty — added `@phpstan-assert non-empty-string $event`, an accurate description of the method's real contract, needed because `WordPressHookBus`'s `do_action()`/`add_action()` calls require `non-empty-string` per the WordPress stubs.
  - `AbstractMigration::apply_schema()`'s declared `@return array<int, string>` didn't match `dbDelta()`'s actual associative (query-string-keyed) return shape. Fixed by re-indexing with `array_values()` at the point of return — nothing consumes this return value's keys, so normalizing to a plain list is a real, minor correctness improvement, not just a type-checker appeasement.
- All of the above verified: `phpcs` exit `0`, `phpstan analyse --memory-limit=1G` exit `0` (`[OK] No errors`), all 31 existing tube-core PHPUnit tests still green — fixes made zero behavioral change, confirmed by the unchanged test suite, not assumed from reading the diff.

### 2.2 `tube-cache` (`Tube_Cache\Cache`)

- `CacheInterface` — `get()`/`set()`/`delete()`/`increment()`. Adopted per `ARCHITECTURE.md` §19.5 on the interface-justification rule (§19.1): the real payoff is `InMemoryCache`, a genuine test fake `RateLimiter` and `CachePurgeSubscriber` are both actually built and tested against, the same pattern already proven by `HookBusInterface` and `SchemaVersionRepositoryInterface` in tube-core.
- `RedisCache implements CacheInterface` — the real implementation, built on `predis/predis` (a pure-PHP Redis client; confirmed empirically that neither the `wordpress:php8.3-fpm` nor `wordpress:cli-php8.3` images ship the `redis` PHP extension, so a Composer-installed client was the only option, not a preference). Every key is prefixed `tube_cache:`. Values are `serialize()`/`unserialize()`d (not JSON, for full PHP type fidelity) with `allowed_classes: false` on read, specifically closing off PHP object-injection even in a compromised-Redis scenario — this project never caches objects, only arrays/scalars, so the restriction costs nothing real. A Redis connection failure degrades every method to a safe default (`get()` → cache miss, `set()`/`delete()` → no-op, `increment()` → 0, chosen to fail *open* for rate limiting specifically) instead of throwing, logged via `error_log()` (per `ARCHITECTURE.md` §18.5's PHP-error-log monitoring) rather than swallowed silently — the cache must never be a single point of failure for page rendering.
- `CacheKeys` — the documented key-naming convention (`video_detail(int $video_id): string` today), so the writer that doesn't exist yet (tube-player, Phase 6) and the purge subscriber that does exist now already agree on the same key.

### 2.3 `tube-cache` (`Tube_Cache\RateLimit`)

- `RateLimiter::attempt(string $key, int $max_attempts, int $window_seconds): bool` — a fixed-window counter built on `CacheInterface::increment()`. No dedicated interface (no realistic second implementation or standalone test-fake need beyond what `CacheInterface`'s own fake already provides — a concrete class, the same as `MigrationRunner`). No consumer yet: the view-recording endpoint (`ARCHITECTURE.md` §12 Phase 4) and per-API-key limiting (§17) are both future callers — building the helper now, ahead of its first caller, is the literal Phase 3 deliverable, the same way Phase 2 built the full event catalog ahead of every event having a real trigger.

### 2.4 `tube-cache` (`Tube_Cache\Events`)

- `CachePurgeSubscriber` — subscribes to `tube_core.video.published`/`updated`/`deleted` via WordPress's native `add_action()` on the literal hook-name strings, deliberately not via `Tube_Core\Events\Dispatcher`/`EventCatalog` as PHP types. Full reasoning is in the class's own docblock; the short version: tube-cache is the one plugin that doesn't declare `Requires Plugins: tube-core` (an independent utility, `ARCHITECTURE.md` §4), every plugin is independently composer-installable so tube-cache's own PHPUnit suite cannot autoload `Tube_Core\*` classes, and the event *names* — not tube-core's PHP classes — are the documented public contract (`ARCHITECTURE.md` §6). A malformed payload (missing/non-numeric `video_id`) is logged and ignored rather than allowed to throw out of a WordPress hook callback — purging must never be able to break the tube-core video save/delete it's reacting to, the same fail-open principle `RedisCache` applies to Redis failures.
- Purges only the video's own detail key (`CacheKeys::video_detail()`) in this phase. `ARCHITECTURE.md` §16's full purge table also describes purging per-category/tag/actor/studio *listing* keys on these same events — building that now would mean purging cache entries for query/listing APIs that don't exist yet (tube-search's query layer is Phase 7; the actor/studio repositories are an explicitly deferred `ARCHITECTURE_FREEZE.md` Flexible Decision). This is the same discipline Phase 2 already established for the five trigger-less catalog events: build what has a real target today, extend when the real target exists, never build speculative machinery against a table or query layer that isn't there yet.

### 2.5 `tube-cache` bootstrap (`Plugin.php`, `tube-cache.php`)

`Plugin.php` mirrors `Tube_Core\Plugin`'s exact shape: a private-constructor lazy singleton with three thin accessors (`cache()`, `rate_limiter()`, and a private `cache_purge_subscriber()`) — well under the 6–8-accessor reconsideration trigger `ARCHITECTURE.md` §19.2 documents for the (rejected) generic service container. `boot()`, wired to `plugins_loaded` in `tube-cache.php` exactly like tube-core's own bootstrap, registers the purge subscriber. Redis host/port come from `TUBE_CACHE_REDIS_HOST`/`TUBE_CACHE_REDIS_PORT` constants (with sane localhost/6379 defaults if undefined), themselves fed from new `REDIS_HOST`/`REDIS_PORT` `.env` values via `docker-compose.yml`'s shared `WORDPRESS_CONFIG_EXTRA` anchor — the same mechanism `DISABLE_WP_CRON` already uses, since Redis config (unlike the DB vars) has no native `WORDPRESS_*` environment variable support in the official images.

---

## 3. Design decisions

1. **`predis/predis` over `ext-redis`** — no PHP extension available in either WordPress image (confirmed by `php -m` inside both containers, not assumed), and a Composer package is trivially fakeable/mockable for the one place it matters (nowhere — `RedisCache` itself is verified live, not unit-tested, matching the `WordPressHookBus` precedent below).
2. **`RedisCache` has no dedicated PHPUnit test; it is verified live instead** — the same split Phase 2 established for `WordPressHookBus` (the thin real `HookBusInterface` implementation): the interface's *fake* (`InMemoryCache`) is what makes dependent logic (`RateLimiter`, `CachePurgeSubscriber`) unit-testable; the thin real adapter over an external system is proven against that real system instead. `phpunit.xml.dist` documents this explicitly.
3. **Hook-name-string subscription over a `Tube_Core\Events\Dispatcher` PHP dependency** — covered in depth in §2.4 above and in `CachePurgeSubscriber`'s own docblock. This is the one Phase 3 decision most worth a second look, so it got one: the alternative (typing `Dispatcher`/`EventCatalog` directly) was seriously considered and rejected because it would make tube-cache's own composer-installed PHPUnit suite unable to autoload the classes its own code referenced — a real, mechanical breakage of "every plugin is independently testable" (`DEVELOPMENT_RULES.md` §2), not a hypothetical one.
4. **`CachePurgeSubscriber`'s three `handle_video_*()` methods stay separate rather than collapsing to one shared callback**, even though today they're functionally identical (each just extracts `video_id` and purges) — per `DEVELOPMENT_RULES.md` §6.5's own guidance, a handful of near-identical one-line method bodies isn't automatically duplication worth extracting away, and keeping one named handler per hook (mirroring `VideoLifecycleEvents`'s shape from Phase 2) is what lets each one grow independently once `ARCHITECTURE.md` §16's real per-event purge differences (published purges more than updated, etc.) get built in a later phase, without first having to re-split a collapsed handler back apart.
5. **Fixed-window rate limiting (`INCR` + conditional `EXPIRE`), not a Lua script** — the industry-standard, simpler approach; its one known imprecision (a process crashing between the two commands leaves a counter with no expiry) is documented directly on `CacheInterface::increment()`'s docblock as an accepted, bounded tradeoff, not glossed over.

---

## 4. Backward compatibility with Phases 0–2

Verified live, not assumed:

- `wp tube migrate status` after this phase's code was loaded: all four tube-core migrations still `yes`/applied, same `applied_at` timestamps as before — untouched.
- `video` CPT and `video_category`/`video_tag` taxonomies still registered (`post_type_exists()`/`taxonomy_exists()` both `true`).
- All eight plugins (including `tube-cache`) still activate and load with zero fatals.
- Deactivating `tube-core` entirely and exercising `tube-cache`'s cache API directly: still works (`set()`/`get()` round-trip succeeds) — confirms tube-cache's independence claim (§2.4) is real, not just documented.
- The event-dispatch mechanics from Phase 2 (`VideoLifecycleEvents` → `Dispatcher` → real WordPress hooks) still fire correctly — this is exactly how the live purge verification in §6 below works, since it depends on that exact path.

---

## 5. Automated tests

**15 new PHPUnit tests for `tube-cache`**, all against fakes — zero WordPress, zero live Redis, zero `Tube_Core` dependency:

- `CacheKeysTest` (2 tests): the key convention is unique per video ID and stable across calls.
- `RateLimiterTest` (6 tests): allows attempts within the limit, rejects the attempt that exceeds it, different keys are independent, rejects non-positive `$max_attempts`/`$window_seconds`.
- `CachePurgeSubscriberTest` (7 tests): `purge_video()` and all three `handle_video_*()` methods delete exactly the right key, purging one video doesn't affect another's cached entry, a malformed payload (missing or non-numeric `video_id`) is ignored rather than thrown, verified via the fake's own `$deleted` call log.

`tube-core`'s existing 31 tests are unaffected and still pass (confirmed, not assumed) after this phase's PHPStan-driven fixes.

## 6. Live verification (real Redis, real WordPress hooks — not just unit tests)

Unit tests exercise fakes; none of them prove `RedisCache` actually talks to Redis correctly or that `CachePurgeSubscriber` is wired to real, live-firing WordPress hooks. Verified directly against the Docker staging environment:

- `RedisCache::get()`/`set()`/`delete()`/`increment()` against the real `redis` container: miss → null, set-then-get round-trips a full array value correctly, delete-then-get returns to null, three sequential increments return 1/2/3.
- Inspected the real Redis keyspace directly (`redis-cli KEYS`/`GET`/`TTL`): confirmed the `tube_cache:` prefix, confirmed the TTL set via `set()` matches exactly, confirmed the stored value is genuinely `serialize()`'d PHP (`s:1:"x";`).
- `RateLimiter::attempt()` against real Redis: three allowed attempts then a rejected fourth, for a limit of 3 — exact expected fixed-window behavior.
- **Full purge lifecycle, across independent WP-CLI processes** (so each step is a genuinely separate WordPress request, the same rigor Phase 2's live event verification used): seeded a `video_detail` cache entry for a real video, published it via `wp post update --post_status=publish` in a separate process, confirmed the Redis key was gone. Repeated for a plain content update (`video.updated`) and a permanent delete (`video.deleted`) — both purged correctly.
- **Graceful degradation**: before the `docker-compose.yml` env change was picked up by a container recreate, `RedisCache` was, unintentionally but usefully, exercised against an unreachable Redis host — every call degraded to its documented safe default (`null`/no-op/`0`) with a logged, non-fatal error, exactly as designed. This was real evidence of the fail-open behavior working, not merely code review of it.
- **Independence from tube-core**: deactivated `tube-core` entirely; `tube-cache`'s own cache API still functioned normally with zero fatals. Reactivated `tube-core` afterward and reconfirmed its migration state was untouched by the deactivate/reactivate cycle.

All test artifacts (the temporary video post, Redis keys) were cleaned up afterward; nothing from this verification is part of the committed state.

## 7. Verification summary

| Check | Result |
|---|---|
| `phpcs` (whole repo) | Exit `0` |
| `phpstan analyse --memory-limit=1G` (whole repo, level `max`) | Exit `0`, `[OK] No errors` |
| `phpunit` (tube-core) | 31/31 passing |
| `phpunit` (tube-cache) | 15/15 passing |
| Phase 0–2 checks after this phase's code loaded | Unaffected (§4) |
| Live Redis round-trip (get/set/delete/increment) | Confirmed correct, including raw keyspace inspection |
| Live purge on real `video.published`/`updated`/`deleted` | Confirmed correct, across independent WP-CLI processes |
| Live graceful degradation on Redis unreachable | Confirmed correct (incidental, real) |
| Live independence from an inactive tube-core | Confirmed correct |

## 8. Explicitly out of scope for Phase 3

Fragment cache and Cloudflare edge-purge (`ARCHITECTURE.md` §16's other two cache layers) — depend on a real theme (Phase 8) and CDN integration that don't exist yet. Listing-key purges (per category/tag/actor/studio) — depend on query/repository layers that don't exist yet (tube-search's query layer, Phase 7; actor/studio repositories, a deferred `ARCHITECTURE_FREEZE.md` decision). The view-recording endpoint and any other `RateLimiter` consumer (Phase 4+). All per `ARCHITECTURE.md` §12.

## 9. Production impact

None. All work happened in the local Docker staging environment. Production (`root@139.99.96.155`) was not accessed.

---

## 10. Implementation Review

Performed before this phase's commit, per `DEVELOPMENT_RULES.md` §7 — every dimension in that section's checklist was walked against the actual diff, re-read fresh. Two real findings were made and fixed during this pass (not deferred, not filed as debt):

1. **Correctness / Security**: `CachePurgeSubscriber`'s handlers originally cast `$payload['video_id']` straight to `int` — a `mixed` value from another plugin's dispatch, trusted without validation. Fixed by adding `extract_video_id()`, which validates presence and numeric-ness and throws a clear `InvalidArgumentException` otherwise, then wrapping that in a `purge_from_payload()` that catches it, logs, and returns rather than letting a malformed payload throw out of a live WordPress hook callback and potentially break the video save/delete it's reacting to. New tests added for both the missing-field and non-numeric cases.
2. **Documentation accuracy**: `CacheInterface::increment()`'s docblock originally called the whole operation "atomic," which overclaims — only the `INCR` itself is atomic; the follow-up conditional `EXPIRE` is a separate, non-atomic step with a small, accepted, documented failure window (§3 point 5). Tightened the wording rather than leaving an inaccurate claim on a documented public contract.

Everything else reviewed clean: no duplicated code beyond the considered-and-accepted point in §3.4, no dead code, no unnecessary SQL (none exists in this phase), no N+1/missing-index concerns (no database access in this phase at all — `tube-cache` has no MySQL tables, `ARCHITECTURE.md` §4), no unnecessary hooks (all three `add_action()` registrations are used), no unnecessary abstractions (`CacheInterface` re-justified per §19.5; `RateLimiter`/`CachePurgeSubscriber` deliberately left as concrete classes, per §3 above), no race conditions beyond the one documented and accepted in `increment()`, no event-ordering assumptions (purging is idempotent regardless of when relative to other listeners it runs), REST API correctness N/A (no routes this phase), WPCS/PSR-12 clean, PHPStan level `max` clean.

## 11. Benchmark Report

Full numbers, methodology, and the one apparent (fully explained, non-regression) jump are in `BENCHMARKS.md`'s new "Phase 3 (tube-cache)" section. Summary: PHP memory, execution time, and SQL query count on the unchanged `MigrationRunner::status()` benchmark operation are unaffected (memory +2.0 MB from Predis's own class footprint being autoloaded, not from that operation itself); REST/page-generation timings are within Phase 2's steady-state range; cache hits/misses are now real numbers (previously N/A) via Redis `INFO stats`, confirmed accurate by construction (1,000 real hits, 1,000 real misses, exactly matching what the benchmark script generated); event dispatch cost rose from ≈0.0002 ms to ≈0.03 ms per dispatch — not a dispatcher regression, but the expected, predicted (Phase 2's own `BENCHMARKS.md` entry called this exact scenario in advance) consequence of `CachePurgeSubscriber` now being a real, live listener on the event the benchmark dispatches, doing one real Redis round-trip per call. No metric regressed in the sense that matters (something got *slower for the same amount of work*); nothing required fixing before this commit.

## 12. Technical Debt Budget

Per `DEVELOPMENT_RULES.md` §10: **zero debt filed, none carried in.** `adr/DEBT-*.md` had no open items targeted at Phase 3 before this phase began (only `adr/DEBT-TEMPLATE.md` exists in the repo). Every scope-narrowing decision made this phase was considered against the "known, intentional gap between what was implemented and what genuinely production-quality implementation of that same piece of `ARCHITECTURE.md` would look like" test in §10, and none of them clear that bar:

- Fragment cache / edge purge / listing-key purge (§8, §2.4): not a gap in *this phase's* implementation — `ARCHITECTURE.md` §12 doesn't assign them to Phase 3, and building them now would mean writing purge logic against query layers and a theme that don't exist yet. There is no "more production-quality" version of Phase 3 that includes them; they belong to later phases, not to a corner cut in this one.
- The hook-name-string subscription instead of a typed `Tube_Core\Events\Dispatcher` dependency (§3.3): this **is** the genuinely correct implementation given the frozen architectural constraint that every plugin stays independently composer-installable — not a lesser version of a better alternative that was skipped for time. The one real, open risk it carries (tube-cache's literal event-name strings could silently drift from tube-core's `EventCatalog` if the latter ever changes) is named explicitly in the class's own docblock and mitigated by this phase's live, end-to-end verification (§6) rather than left implicit.
- `RedisCache` having no dedicated PHPUnit test (§3.2): the same already-accepted, already-precedented category of gap as `WordPressHookBus` in Phase 2 — a deliberate, disclosed choice with a stated mitigation (live verification), not a new one introduced here.
- `CacheInterface::get()`'s inability to distinguish a cached `null` from a miss: explicitly sanctioned as a Flexible Decision in `ARCHITECTURE_FREEZE.md` ("the interfaces themselves are frozen, their exact surface area is not") — free to extend later if a real caller ever needs it, not a corner cut today.

No Debt ADR filed. `ARCHITECTURE-CHANGELOG.md` is unchanged — no architecture decision changed this phase, frozen or otherwise.

---

Phases 0–3 are implemented, tested, and committed. Further implementation continues phase by phase, per `DEVELOPMENT_RULES.md` — waiting for explicit approval before Phase 4.
