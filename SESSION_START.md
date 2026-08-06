# Session Start — Handoff for a Fresh Session

This file exists because **sessions have no memory of prior conversations.** It is a snapshot, written at the end of Phase 9, of where this project stands and what a new session needs to know before touching it. It is a *summary with pointers*, not a replacement for the canonical documents — where this file and one of those documents ever disagree, the canonical document wins; update this file, not your assumptions.

**This file itself is not a rule source.** `DEVELOPMENT_RULES.md` and `ARCHITECTURE.md` are. Read them in full before writing any code — see §3 below for the exact mandatory reading list and order.

---

## 1. Current project status

- **Current commit**: `e2c573d` — "Phase 9: tube-seo video XML sitemap generation" (branch `main`, working tree clean at time of writing).
- **Current phase**: Phase 9 is complete and committed. **Phase 10 has not started.** Per `DEVELOPMENT_RULES.md` §1, do not begin Phase 10 without the user's explicit approval, even if this file says it's "next."
- **Completed phases (0–9)**, each with its own `PHASE-X.md` evidence report:

| Phase | Deliverable | Report |
|---|---|---|
| 0 | Staging environment, repo structure, Composer/PHPCS tooling, CI, cron skeleton | `PHASE-0.md` |
| 1 | `tube-core` foundation: CPT, taxonomies, migration runner, `wp_tube_video_metadata` | `PHASE-1.md`, `PHASE-1-AUDIT.md` |
| 2 | `tube-core`: event dispatcher | `PHASE-2.md` |
| 3 | `tube-cache`: Redis cache, rate limiter, `video.*` purge subscriber; PHPStan level `max` established | `PHASE-3.md` |
| 4 | `tube-core`: `video_views`/`video_statistics`, Redis-buffered view recording, stats rollup | `PHASE-4.md` |
| 5 | `tube-core`: import pipeline, Cloudflare Stream webhook, watch history | `PHASE-5.md` |
| 6 | `tube-player`: Stream playback, image rendering | `PHASE-6.md` |
| 7 | `tube-search`: search index, discovery (related/trending/most-viewed/latest), full-text search | `PHASE-7.md` |
| 8 | Theme presentation layer **+ tube-seo's meta/JSON-LD/pagination metadata** (pulled forward from Phase 9's original scope — see `ARCHITECTURE-CHANGELOG.md`'s 2026-08-05 entry) | `PHASE-8.md` |
| 9 | `tube-seo`: video XML sitemap generation (the one piece Phase 8 didn't pull forward) | `PHASE-9.md` |

- **Remaining phases (10–12)**, per `ARCHITECTURE.md` §12 (this table is the authoritative source — re-read it, don't trust this copy if it's ever stale):

| Phase | Deliverable |
|---|---|
| 10 | `tube-admin`: import dashboard, statistics dashboard, custom-poster upload UI, bulk tools, settings UI |
| 11 | Scale hardening: read-replica routing, partition rollout/retention verification, load test at simulated 500k-video/high-pageview volume, edge cache tuning |
| 12 | QA, security review (REST auth/nonces, `$wpdb->prepare()` audit across all six tables, migration rollback drill), staging → production cutover |

- **Open ADRs**: none. `adr/` contains only `TEMPLATE.md` and `DEBT-TEMPLATE.md` — no architecture ADR and no Debt ADR has ever been filed. Technical Debt Budget is currently zero (confirmed as of Phase 9's Implementation Review, `PHASE-9.md` §12).

---

## 2. Current architecture

Full detail lives in `ARCHITECTURE.md` (Revision 5, frozen) and `ARCHITECTURE_FREEZE.md`. Summary only — **do not implement from this summary; read the source files.**

### Frozen decisions (changing any of these requires the full ADR process — `DEVELOPMENT_RULES.md` §8)

1. Six independent plugins (`tube-core`, `tube-cache`, `tube-search`, `tube-player`, `tube-seo`, `tube-admin`) + a presentation-only theme; no plugin depends on another's internals/tables directly; only `tube-core` has no plugin dependency.
2. `video` is a Custom Post Type, never native `post`.
3. `actor`/`studio` are dedicated tables, never taxonomies. `video_category`/`video_tag` remain native taxonomies.
4. No video/image bytes stored on the WordPress server — only Cloudflare Stream/Images identifiers, never playback URLs.
5. `wp_postmeta` is never used for video data.
6. Every schema change is a migration with a genuinely reversible `down()`, self-registered per plugin.
7. WP-Cron is never used — every scheduled task runs via Linux cron invoking WP-CLI (`DISABLE_WP_CRON` is `true`).
8. The event system (`Dispatcher`/`EventCatalog`/`HookBusInterface`) is the only sanctioned cross-plugin reaction mechanism; handlers stay cheap/synchronous, expensive work goes to cron.
9. Import pipeline is a DB-table queue (`wp_tube_import_queue`) + WP-CLI batch worker, not a message broker.
10. Search starts on MySQL FULLTEXT; no search-engine infrastructure without a concrete, data-backed case.
11. No generic service container; dependencies are constructor-injected or obtained via a plugin's own typed bootstrap accessors (`Plugin::instance()->x()`).
12. An interface is created only when it has a realistic second implementation (real competing implementation or a genuinely-used test fake) — never for hypothetical vendor-swap flexibility.
13. `/tube/v1` REST namespace is additive-only; breaking changes require `/tube/v2` alongside it.
14. Bulk relationship-table writes use one multi-row `INSERT`, never a loop of single-row inserts.
15. PHP 8.3 only, `declare(strict_types=1)` everywhere, PSR-12 + WPCS per `phpcs.xml`'s documented reconciliation.
16. Production is never modified directly — all implementation happens in local Docker staging.

*(Full list with reasoning: `ARCHITECTURE_FREEZE.md` "Frozen decisions" section — 17 items there; this is a compressed summary.)*

### Deferred decisions (not decided now; each has an explicit trigger — see `ARCHITECTURE_FREEZE.md` "Deferred decisions")

- Read/write MySQL replica routing — triggered by "once traffic requires it," Phase 11's concern.
- Elasticsearch/OpenSearch for search — only if MySQL FULLTEXT is *measured* inadequate.
- Image/CDN provider abstraction beyond `VideoProviderInterface` — only if a concrete need materializes.
- `Plugin.php` per-plugin container/memoization helper — only past the 6–8-accessor trigger (not yet hit anywhere; `Tube_Core\Plugin` is the largest at 8).

### Explicit non-goals (`ARCHITECTURE_FREEZE.md`)

- Not building literal microservices.
- Not self-hosting video/image transcoding or storage, ever, absent a new functional requirement.
- Not building a generic service container or DI framework.
- Not adopting a general-purpose message broker.
- Not depending on a third-party SEO, movie/tube, or premium theme/plugin anywhere.
- Not supporting PHP < 8.3 or WordPress < 6.5.
- Not building admin or public features not named in `ARCHITECTURE.md`'s phases — no speculative feature work ahead of its phase.

### Project scale assumptions

Designed for **500,000+ videos and millions of pageviews/month** (`ARCHITECTURE.md` §10). Current real data volume is near-empty (staging has a handful of test videos) — every design decision targets the 500k number, not today's row counts.

### Performance assumptions (`ARCHITECTURE_FREEZE.md` "Performance assumptions")

- MySQL FULLTEXT assumed adequate through ~500k videos under *moderate* relevance-ranking needs (no fuzzy matching, no complex weighted ranking).
- Redis assumed to comfortably handle view-counter buffering + object cache + rate-limiting simultaneously at millions-of-pageviews/month, given operationally-managed memory sizing.
- DB-table import queue assumed adequate for the full 500k-video initial catalog load over hours/days, not required to complete in minutes.
- A single MySQL primary is assumed sufficient until the read-replica trigger fires.
- The PHP/application layer is horizontally scalable as designed — no server-local state, no PHP sessions, no in-process-only caching, no local file writes *for request-serving paths* (Phase 9's sitemap files are a WP-CLI/cron-time write, not a request-time one — see `PHASE-9.md`).

### Technology stack

- **PHP 8.3**, `declare(strict_types=1)` everywhere, PSR-12 + WordPress Coding Standards (combined ruleset in `phpcs.xml`), PHPStan level `max` (`szepeviktor/phpstan-wordpress` stubs).
- **WordPress** (`wordpress:php8.3-fpm` / `wordpress:cli-php8.3` Docker images), **MariaDB 11.4**, **Redis 7** (cache + rate limiting + view-counter buffering), **nginx 1.27** (reverse proxy), a dedicated **cron sidecar container** (Linux `crond`, bakes `ops/cron/staging.cron` in at build time — a `docker compose build cron` is required after any crontab change).
- **Cloudflare Stream** (video hosting/playback) + **Cloudflare Images** (deferred — no abstraction built yet) — the only external media infrastructure; nothing self-hosted.
- Six independent Composer-installable plugins (`tube-core`, `tube-cache`, `tube-search`, `tube-player`, `tube-seo`, `tube-admin` — the last not yet built) + one presentation-only theme (`tube-theme`), each with its own `composer.json`/PSR-4 autoloading/PHPUnit config. One root `composer.json`/`phpcs.xml`/`phpstan.neon.dist` covers dev tooling across all of them (not a shared runtime dependency tree).
- **PHPUnit 10.5** — every plugin has a `phpunit.xml.dist` (pure/fake-backed unit tests, no WordPress) and, where it has real WordPress-coupled logic worth integration-testing, a `phpunit-integration.xml.dist` (real WordPress + MySQL, run inside the `wpcli` Docker container — `tube-cache` currently has no integration suite, pre-existing, not a gap introduced recently).

---

## 3. Mandatory files to read before writing any code

In this order, per `DEVELOPMENT_RULES.md` §11's Session Start Checklist:

1. **`ARCHITECTURE.md`** — in full. The approved, frozen architecture (Revision 5).
2. **`DEVELOPMENT_RULES.md`** — in full. Process/quality rules; binding regardless of what any conversation recalls.
3. **`ARCHITECTURE_FREEZE.md`** — what's frozen, flexible, and deferred, before proposing or implementing anything that touches architecture.
4. **The most recent `PHASE-X.md`** (currently `PHASE-9.md`) and any `PHASE-X-AUDIT.md` (currently `PHASE-1-AUDIT.md`) — what's already built and verified. Reading only the most recent one is the documented minimum; reading all of `PHASE-0.md` … `PHASE-9.md` gives fuller context and is recommended for a session about to start a new phase, but §11 only requires the latest.
5. **`BENCHMARKS.md`**'s most recent section ("Phase 9") — current performance baselines, so a regression can actually be recognized as one.
6. **`adr/DEBT-*.md`** (list the directory — currently empty of filed ADRs, only templates exist) — outstanding technical debt and its target removal phase.
7. **`git log --oneline`** — compare against what the phase docs claim; the committed state is the source of truth, not any document's memory of it.
8. **`ARCHITECTURE-CHANGELOG.md`** — every post-approval architecture change/reconciliation, in date order (currently 3 entries: the pre-freeze Revision 5 optimization pass, the Phase 9-scope reconciliation after Phase 8's pull-forward, and — check the file directly for the exact current list).
9. **`phpcs.xml`** and **`phpstan.neon.dist`** — not rules documents per se, but the actual enforced configuration (WPCS/PSR-12 conflict resolutions, PHPStan bootstrap/paths) — read before assuming what "passing lint" means in this project.

---

## 4. Coding rules

Full text in `DEVELOPMENT_RULES.md` — summarized here for orientation only.

- **Phase discipline (§1)**: exactly one phase at a time, exactly as `ARCHITECTURE.md` §12 defines it — no more, no less. No redesigning what's already specified. No new architectural patterns or abstractions beyond what's already called for. **Stop and wait for explicit user approval after every phase** — never self-judge a phase "done enough" and continue into the next.
- **Architecture Drift Report (§6)**: a quick 8-criteria confirmation-of-no-drift check, run before a phase's *new* work starts (circular deps, service locator, hidden singletons, God classes, duplicated abstractions, unnecessary interfaces, premature optimization, plugin-boundary violations). While frozen, finding nothing is the expected outcome, not a prompt to dig for something to redesign.
- **Implementation Review (§7)**: run before *every* commit (not just phase-completion ones). Review the diff as if written by someone you don't trust, across ~20 named dimensions (correctness, readability, performance, security, N+1 queries, cache invalidation, race conditions, dead code, unnecessary abstractions, etc. — full list in the file). If a meaningful improvement is available, make it before committing; this is a gate, not a report filed for later.
- **PHPCS**: combined WPCS + PSR-12 ruleset (`phpcs.xml`), exit `0` required, zero errors and zero warnings unless a warning is a deliberate, documented `phpcs:ignore` with a reason.
- **PHPStan**: level `max`, `szepeviktor/phpstan-wordpress` stubs, zero errors, whole repo (`vendor/bin/phpstan analyse --memory-limit=1G` from the repo root, config in `phpstan.neon.dist`).
- **Unit tests**: pure logic / interface-backed-by-a-fake only, no WordPress bootstrap. Per-plugin `phpunit.xml.dist`. An interface is only created when it clears the "realistic second implementation" bar (§2's rule) — WordPress-coupled orchestrators with no real second implementation stay integration-tested only (e.g. `SeoHead`, `SitemapGenerator`).
- **Integration tests**: real WordPress + MySQL, run inside the `wpcli` Docker container via `phpunit-integration.xml.dist` where one exists (`docker compose exec wpcli bash -lc "cd /var/www/html/wp-content/plugins/<plugin> && vendor/bin/phpunit -c phpunit-integration.xml.dist"`).
- **Benchmark policy (§9)**: after a phase's implementation is otherwise complete (code + Implementation Review + green tests) and before its commit, run `ops/benchmark/run.sh` against live staging, append a new dated section to `BENCHMARKS.md` (append-only, never edit a prior phase's numbers), and compare every metric against the immediately preceding phase's section. A real regression must be fixed before committing (or, if genuinely irreducible without touching frozen architecture, may itself justify an §8 ADR).
- **Technical debt policy (§10)**: zero by default, every phase. A genuine, intentional gap requires a Debt ADR (`adr/DEBT-NNNN-*.md`, `adr/DEBT-TEMPLATE.md`) filed in the *same commit* — justification, impact, removal plan, target removal phase. No debt is ever left undocumented, even for one commit. Before a phase's new work starts, check for any open Debt ADR targeted at that phase and pay it off as part of its scope.
- **Environment (§3)**: never modify production (`root@139.99.96.155`) directly — all work happens in local Docker staging.
- **Commit discipline (§4)**: commit only when a phase (or an explicitly-scoped follow-up) is genuinely complete; commit messages explain *why*, not just *what*; never force-push or amend an already-discussed-as-complete commit.

---

## 5. Current metrics (as of commit `e2c573d`, Phase 9)

- **Unit tests**: 144/144 passing across all five plugins with a unit suite — `tube-core` 63, `tube-cache` 22, `tube-player` 9, `tube-search` 23, `tube-seo` 27. Run per-plugin: `cd wp-content/plugins/<plugin> && vendor/bin/phpunit`.
- **Integration tests**: 67/67 passing across the four plugins with an integration suite — `tube-core` 23, `tube-player` 7, `tube-search` 23, `tube-seo` 14. `tube-cache` has no `phpunit-integration.xml.dist` (pre-existing, not a Phase 9 gap). Run inside the `wpcli` container (see §4).
- **PHPCS**: exit `0`, whole repo, 210 PHP files (`vendor/bin/phpcs` from repo root — the `parallel=8` progress display shows worker count, not file count; confirm actual file count with `vendor/bin/phpcs -v | grep -c '\.php'` if it ever looks suspiciously low).
- **PHPStan**: level `max`, whole repo, 210 files, `[OK] No errors` (`vendor/bin/phpstan analyse --memory-limit=1G` from repo root).
- **Benchmark baseline**: `BENCHMARKS.md`'s "Phase 9" section is current. Every tracked metric is unchanged from Phase 8 within normal run-to-run noise (Phase 9 touches no benchmarked request path). Key steady-state numbers to compare Phase 10 against: PHP memory 53.313 MB (`MigrationRunner::status()`), 2 SQL queries for that same operation, page generation 11.09–13.75 ms (`/watch/test-video-one/` and `/`), REST latency 12.14–19.83 ms, event dispatch ≈0.096–0.101 ms/dispatch, import throughput ~1,170–1,218 items/second. Full detail and methodology in `BENCHMARKS.md` directly — don't treat this bullet list as authoritative once a newer section exists.

---

## 6. Current repository state

- **Branch**: `main` (the only branch in use).
- **Latest commit**: `e2c573d` — "Phase 9: tube-seo video XML sitemap generation".
- **Working tree**: clean, nothing staged or unstaged, at the time this file was written. **Verify this again in the new session** (`git status`) — don't trust this line if any time has passed or any other process may have touched the repo.
- **Docker staging stack**: was running at time of writing (`db`, `redis`, `nginx`, `wordpress`, `wpcli`, `cron` — `docker compose ps` to confirm current state; `docker compose up -d` to bring it up if it's down). The `cron` container was rebuilt during Phase 9 to pick up the real `sitemap:generate` command — if `ops/cron/staging.cron` is ever edited again, remember `docker compose build cron && docker compose up -d cron` is required (it's baked in at image build time via `ops/docker/cron/Dockerfile`'s `COPY`, not volume-mounted).

---

## 7. Exact scope of Phase 10

Per `ARCHITECTURE.md` §12's table row: **`tube-admin`: import dashboard, statistics dashboard, custom-poster upload UI, bulk tools, settings UI.**

This is the authoritative one-line definition. It has not been elaborated further anywhere in `ARCHITECTURE.md`, `ARCHITECTURE_FREEZE.md`, or any `PHASE-X.md` — no prior phase built any part of `tube-admin` (it's a brand-new, currently-empty plugin slot; `ARCHITECTURE_FREEZE.md`'s per-subsystem table lists it as "Yes (design) / Low / Yes / No / N/A — not built / No / None found," i.e. reviewed and frozen at the design level, zero code exists). **Do not treat the elaboration below as pre-approved scope-expansion — it is this file's best-effort unpacking of the one-line deliverable against what already exists to build against, not a substitute for re-reading `ARCHITECTURE.md` yourself and getting explicit user sign-off on the concrete task breakdown before writing code**, per `DEVELOPMENT_RULES.md` §1's phase-discipline rule.

### What belongs in Phase 10 (reasoned from the deliverable list + what already exists for it to sit on top of)

- **Import dashboard** — a `wp-admin` UI surface over `tube-core`'s existing `wp_tube_import_queue` table and `ImportQueueRepository`/`VideoImporter`/`BatchProcessor` (built Phase 5) — almost certainly status/progress visibility and manual trigger/retry controls over an already-real backend, not new import logic itself.
- **Statistics dashboard** — a `wp-admin` UI surface over `tube-core`'s existing `wp_tube_video_statistics` table (built Phase 4) — views_total/today/7d/30d, most-likely a sortable/filterable admin table, not new aggregation logic (`StatsRollup` already computes these).
- **Custom-poster upload UI** — writes to `wp_tube_video_metadata.poster_image_id`/`og_image_id` (columns that have existed since Phase 1 but have never had a write path built for them) — the Cloudflare Images vs. local-media-library question (`ARCHITECTURE.md` §13, open decision #5) is squarely Phase 10's to resolve or escalate, not something to guess at silently.
- **Bulk tools** — plural and unspecified; likely candidates given what exists: bulk category/tag/actor/studio assignment (the write API for actor/studio assignment doesn't exist yet anywhere — `PHASE-8.md` §3.2 explicitly deferred it to "Phase 10... alongside the write API that would actually keep [`video_count`] accurate," and multiple Phase 8/9 integration tests seed `wp_tube_video_actors`/`wp_tube_video_studios` directly via `$wpdb` specifically because "no write API exists yet (Phase 10)" — **this write API is very likely required Phase 10 scope**, not just UI, since the bulk tools and the actor/studio assignment feature both need it to mean anything), possibly bulk re-import/reprocess triggers.
- **Settings UI** — likely surfaces the Cloudflare/Redis configuration currently only set via `WORDPRESS_CONFIG_EXTRA` constants in `docker-compose.yml` (webhook secret, Stream customer code, signing key, Images account hash) — scope/shape not otherwise specified; confirm with the user before assuming which settings move into an admin UI vs. staying environment-defined.
- **New `tube-admin` plugin scaffold** — `tube-admin.php`, `composer.json`, `phpunit.xml.dist`/`phpunit-integration.xml.dist`, `includes/Plugin.php` (`instance()`/`boot()`/`activate()`/`deactivate()`, matching the exact shape every other plugin's `Plugin.php` already uses — see `tube-seo/includes/Plugin.php` post-Phase-9 as the most recently-built reference), following the frozen migration contract (§3) even if Phase 10 turns out to need no tables of its own beyond what already exists.
- **`Requires Plugins` wiring** — `tube-admin` almost certainly depends on `tube-core` (queue/stats repositories) and, if the actor/studio write API lands here, on nothing else new (those tables are already `tube-core`'s).

### What explicitly does NOT belong in Phase 10

- **Public-facing features** — `tube-admin` is `wp-admin`-only per its one-line deliverable and per its listing in `ARCHITECTURE.md` §4's plugin diagram (`tube-admin (wp-admin UI; no tables)` — note: "no tables" there may need re-checking live against whether the actor/studio write API needs its own new table or writes to Phase 1's existing `wp_tube_actors`/`wp_tube_studios`/`wp_tube_video_actors`/`wp_tube_video_studios` — almost certainly the latter, since those tables already exist).
- **Anything from Phase 11's scope** — read-replica routing, partition rollout/retention *verification* (Phase 4 already built the retention job itself; Phase 11 verifies it at scale), load testing, edge cache tuning.
- **Anything from Phase 12's scope** — security review, REST auth/nonce audit, migration rollback drill, production cutover.
- **Any architecture change** — if Phase 10's actual requirements turn out to need something `ARCHITECTURE.md`/`ARCHITECTURE_FREEZE.md` doesn't already provide for (e.g. a new table shape, a new REST surface, a settings-storage decision not already covered by the migration contract's "future options schema" note), that's an §8 ADR trigger to flag explicitly to the user, not something to design around silently.
- **Re-litigating any Phase 0–9 decision** — e.g. do not revisit `video_count`'s live-`COUNT()`-vs-denormalized-column tradeoff (`PHASE-8.md` §3.2) beyond what Phase 10 actually requires to finally wire up write-side maintenance for it, since that was explicitly named as Phase 10's job already.

### Acceptance criteria (derived from every prior phase's own closing pattern — not itself part of `ARCHITECTURE.md`, apply the same bar prior phases were held to)

- Every deliverable in the one-line scope has a real, working `wp-admin` UI, backed by real data (no placeholder/mock content).
- Architecture Drift Report run before starting, clean or with findings fixed (`DEVELOPMENT_RULES.md` §6).
- Implementation Review run before commit, clean or with findings fixed (§7).
- `phpcs` exit `0`, `phpstan` level `max` clean, whole repo.
- Unit tests for all new pure logic; integration tests for all new WordPress-coupled logic against real seeded data.
- Live verification in Docker staging (every new admin screen actually loaded and exercised with real data, not just "tests pass").
- Benchmark Report run and compared against Phase 9's section in `BENCHMARKS.md` (§9) — `wp-admin`-only screens are unlikely to move any currently-tracked metric, but the comparison must still be done and stated, not skipped.
- Technical Debt Budget zero, or a filed Debt ADR (§10).
- `PHASE-10.md` written with real evidence (command output, live-check results), matching every prior `PHASE-X.md`'s structure.
- Backward compatibility with Phases 0–9 verified live, not assumed.
- Commit only after every gate passes; **stop and wait for explicit user approval before Phase 11.**

### Deliverables

1. `tube-admin` plugin (new): scaffold, import dashboard, statistics dashboard, custom-poster upload UI, bulk tools, settings UI — each backed by real `tube-core` data.
2. Likely (verify against real Phase 10 requirements, don't assume without confirming): `tube-core` actor/studio write API (repository write methods + any needed REST/admin-AJAX surface) — flagged above as very likely necessary for the "bulk tools"/custom-assignment scope to mean anything, and explicitly named as Phase 10's job in `PHASE-8.md` §3.2.
3. `PHASE-10.md`.
4. `BENCHMARKS.md` "Phase 10" section.
5. Updated `ARCHITECTURE-CHANGELOG.md` only if an actual architecture decision is made (e.g. settings-storage mechanism, if it's not already covered by "future options schema").

---

## 8. Copy-paste prompt for a new session

The block below is meant to be copied verbatim into a brand-new Claude Code conversation in this repository.

```
NEW_SESSION_PROMPT

Before writing or changing any code, do the following, in order:

1. Read SESSION_START.md in full — it's a handoff snapshot written at the end of Phase 9 covering project status, architecture summary, mandatory reading list, coding rules, current metrics, repo state, and Phase 10's scope.

2. Read the mandatory files it points to, in the order SESSION_START.md §3 lists them:
   - ARCHITECTURE.md (in full)
   - DEVELOPMENT_RULES.md (in full)
   - ARCHITECTURE_FREEZE.md
   - PHASE-9.md (the most recent phase report) — and PHASE-0.md through PHASE-8.md if you want fuller context before starting new work
   - BENCHMARKS.md's most recent ("Phase 9") section
   - adr/ directory listing (confirm no open Debt ADRs)
   - git log --oneline
   - ARCHITECTURE-CHANGELOG.md
   - phpcs.xml and phpstan.neon.dist

3. Verify current repository state yourself — do not trust SESSION_START.md's snapshot without checking:
   - git status (expect clean)
   - git log -1 (expect commit e2c573d as the parent, unless something has since been committed — if so, treat that as the real current state and note the discrepancy)
   - docker compose ps (bring the stack up with docker compose up -d if it's not running)
   - Run the full lint/typecheck gate: vendor/bin/phpcs (expect exit 0) and vendor/bin/phpstan analyse --memory-limit=1G (expect "[OK] No errors")
   - Run every plugin's unit suite and, inside the wpcli container, every plugin's integration suite — confirm the counts match SESSION_START.md §5 (144 unit / 67 integration) or note and investigate any discrepancy before proceeding

4. Perform the mandatory pre-phase checks per DEVELOPMENT_RULES.md §1 and §6:
   - Run the Architecture Drift Report (§6's 8 criteria) against the current codebase, fresh — do not reuse this file's Phase 9 findings.
   - Check adr/DEBT-*.md for any open debt targeted at Phase 10 (expected: none, but verify).

5. Only after all of the above is genuinely done — not assumed — begin Phase 10 exactly as scoped in SESSION_START.md §7 and ARCHITECTURE.md §12's Phase 10 row ("tube-admin: import dashboard, statistics dashboard, custom-poster upload UI, bulk tools, settings UI"). Before writing implementation code, confirm the concrete task breakdown with me (the user) — SESSION_START.md §7's elaboration is a best-effort unpacking of a one-line deliverable, not pre-approved scope. Follow DEVELOPMENT_RULES.md §1's phase-discipline rules throughout: exactly this phase's scope, no architecture redesign, no new abstractions beyond what's already specified, Implementation Review before every commit, and stop for my explicit approval before Phase 11.
```

---

*Written at the end of Phase 9 (commit `e2c573d`). If this file is ever more than a phase or two old when read, treat every specific number/hash/count in it as a starting point to re-verify, not a fact to build on unchecked.*
