# Pre-Phase-3 Architecture Drift Report & Enterprise Architecture Review

Status: **For decision. No code changed. No `ARCHITECTURE.md` edits made yet.**

This document precedes Phase 3 and is not itself a `PHASE-X.md` — no `PHASE-3.md` exists yet, since Phase 3 hasn't been approved to start (per `DEVELOPMENT_RULES.md` §1, a phase isn't begun without explicit approval). Once Phase 3 starts, its `PHASE-3.md` will link back to this document rather than duplicate it. Every finding below was checked against the actual repository state (fresh `git log`, fresh file reads, fresh `grep`, fresh `phpcs`/`phpunit` runs), not reconstructed from memory of writing the code.

---

## Part 1 — Architecture Drift Report

### 1. Does every implementation still match the architecture?
Yes. `video` CPT args (§1.1), `video_category`/`video_tag` taxonomies (§1.2), all four applied migrations' schemas (§2.1, §14.2/§14.3, as amended by `PHASE-1-AUDIT.md`), and the event catalog (§6, 9/9 events present, correctly Active/Reserved) were re-checked against the current source, not re-asserted from memory. No drift found.

### 2. Has any temporary shortcut accidentally become permanent?
No. Fresh `grep -rniE "TODO|FIXME|XXX:|not implemented|temporary hack"` across all of `tube-core` (excluding `vendor/`) returns zero matches.

### 3. Is there any hidden coupling between plugins?
No. `tube-core` is the only plugin with real code; fresh `grep` for `Tube_Player`/`Tube_Search`/`Tube_Seo`/`Tube_Admin`/`Tube_Cache` inside `tube-core` returns zero matches. No plugin's `composer.json` requires another `tube-*` package (checked all six fresh).

### 4. Are there any duplicated responsibilities?
**Yes — one real finding.** `SchemaVersionStore` calls `global $wpdb;` five separate times (once per method), while `AbstractMigration` already solved exactly this with a single `db(): wpdb` accessor that every migration class reuses. Two different patterns exist in the codebase for the same responsibility ("get the database connection"). See Part 2, Dependency Injection / Database recommendations, for the fix.

### 5. Are there future scalability problems for 500k+ videos?
None blocking. Two items are worth watching but are correctly *not* acted on yet, since nothing consumes them: a composite `(cf_status, created_at)` index on `wp_tube_video_metadata` (useful for a future "stuck in processing" admin query, Phase 10) and an index on `wp_tube_actors.video_count`/`wp_tube_studios.video_count` (useful for a future "sort by popularity" query). Adding either now, with no consumer, would itself be the premature optimization Part 1 §7 and `DEVELOPMENT_RULES.md` §6.7 both prohibit.

### 6. Are there database design improvements available?
Yes, one: see finding #4 — consolidating database-connection access is as much a database-design cleanliness item as a DI one. No schema-level improvement was found beyond what `PHASE-1-AUDIT.md` already applied.

### 7. Are there opportunities to reduce queries or memory usage?
None available yet — the only queries in the codebase are `SchemaVersionStore`'s (already minimal: indexed lookups, no `SELECT *`, no unbounded results). No presentation or listing code exists yet to have a query-count problem.

### 8. Does every public API still respect versioning rules?
Not yet applicable — no REST routes exist yet (`tube/v1` is first populated in later phases). The `/tube/v1`-additive, `/tube/v2`-for-breaking-changes policy (§9) is unchanged and unchallenged since nothing has tested it against a real endpoint yet.

### 9. Does every migration remain reversible?
Yes, re-verified. All four migrations were re-confirmed live in `PHASE-1-AUDIT.md` and Phase 2's backward-compatibility check (`wp tube migrate status` after reactivation) — nothing since has touched the migration files.

### 10. Does every class still have a single responsibility?
Yes, with the one caveat already named in #4: `SchemaVersionStore` and `AbstractMigration` both currently "own" database-connection access independently, which is a minor SRP-adjacent inconsistency (two owners of one responsibility) rather than any single class doing too much.

### 11. Does every plugin remain independently testable?
Yes, re-verified — all six `composer.json` files checked fresh, each self-contained.

### 12. Does every service remain dependency-injected instead of hard-coupled?
Mostly yes (`MigrationRunner`, `Dispatcher`, `VideoLifecycleEvents` all take their dependencies via constructor). `Plugin.php`'s two accessor methods (`migration_runner()`, `events()`) are the composition root, not a violation — but see Part 2's Dependency Injection recommendation for why this pattern needs a decision before it's copy-pasted a third and fourth time.

### 13. Are cache invalidation rules still correct?
Not yet applicable — `tube-cache` doesn't exist yet. §16's policy is unchanged and unchallenged since nothing has been built against it.

### 14. Are event listeners still asynchronous where appropriate?
Not yet applicable — no listeners exist yet beyond the dispatcher itself (nothing subscribes to any event today). `EVENTS.md`'s existing guidance ("stay fast or delegate to cron") is unchanged.

### 15. Is anything violating WPCS or PSR-12?
No. Fresh `phpcs --standard=phpcs.xml` run: exit `0`, zero errors, zero warnings, across the whole repo. Fresh `phpunit` run: 31/31 passing.

**Drift Report conclusion**: one real finding (#4/#6/#10, the `$wpdb` access duplication), addressed as a recommendation in Part 2 rather than fixed silently here, since it's better handled together with the related Dependency Injection question below — fixing it in isolation right now would mean revisiting it again once Part 2 is decided.

---

## Part 2 — Enterprise Architecture Review

Reviewed as if leading this codebase for a company expecting 500,000+ videos, millions of monthly pageviews, multiple developers, and years of maintenance. Every topic was genuinely reconsidered, not compared against the original document for compliance — several are recorded as **"reviewed, no change"** with the reasoning, which is as much a real output of this kind of review as a proposed change is; forcing a "better idea" onto a topic that's already correctly designed would itself be bad architecture.

### Dependency Injection

**Current design**: Constructor injection where an interface exists (`MigrationRunner`, `Dispatcher`). `Plugin.php` is the composition root, with one hand-written lazy-singleton accessor per service (`migration_runner()`, `events()`). No formal container.

**Proposed design**: Introduce a minimal service container in `tube-core` (a small `Container` class supporting `set(string $id, callable $factory)` / `get(string $id): mixed`, no auto-wiring/reflection magic needed — that would be over-engineering for this project's actual needs). `Plugin.php`'s two accessors become two `$container->get(...)` registrations instead of two hand-written methods; every future service (Phase 3's cache client, Phase 5's import processor, Phase 7's search client) registers the same way instead of `Plugin.php` growing a new method per service.

**Benefits**: `Plugin.php` was already flagged as a God-class watch point in the Phase 2 regression review — this removes that growth path entirely rather than just watching it. A container is also the natural seam for the read/write database-routing recommendation below (register a "write connection" and "read connection" factory once, every consumer resolves through the container instead of `global $wpdb`). Cheap to retrofit now (2 services); expensive later (a 10-service `Plugin.php` with hand-written accessors is a much larger refactor under time pressure).

**Risks**: A new core abstraction other plugins must learn and use consistently — if a future phase bypasses it and reaches for `global $wpdb` or a new one-off singleton instead, the container provides no value. Mitigated by making it the *only* documented way to obtain a shared service (a rule for `DEVELOPMENT_RULES.md`, not just a class that exists).

**Migration cost**: Low. Two existing accessors (`migration_runner()`, `events()`) get rewritten to route through the container; both have full test coverage already, so the refactor is verifiable immediately.

**Adopt now or postpone**: **Adopt now**, before Phase 3 adds `tube-cache`'s Redis client as a third service using whatever pattern is current at that point.

### Repository pattern

**Current design**: `SchemaVersionStore` is a repository in function (implements `SchemaVersionRepositoryInterface`) but the term "repository" is never used as a documented convention. No repositories exist yet for `wp_tube_video_metadata`/`wp_tube_actors`/`wp_tube_studios` — nothing consumes those tables yet, so none were built (correctly — building them with no consumer would be the exact premature-optimization criterion in `DEVELOPMENT_RULES.md` §6.7).

**Proposed design**: Formalize the convention in `ARCHITECTURE.md` now, in writing, before Phase 4+ builds the first real domain repository: every dedicated table gets one repository class, named `{Noun}Repository`, implementing a `{Noun}RepositoryInterface`, following `SchemaVersionStore`/`SchemaVersionRepositoryInterface`'s exact shape. This is a documentation change, not a code change — there is nothing to build yet.

**Benefits**: Without this written down now, six different plugins built by (potentially) different developers over years will each invent their own data-access style the first time they need one, and reconciling that later costs far more than stating the convention once, today, while there's exactly one example to point to.

**Risks**: None — it's a naming/structure convention, not new code or new runtime behavior.

**Migration cost**: Zero.

**Adopt now or postpone**: **Adopt now** (documentation only).

### Storage abstraction

**Current design**: Cloudflare Stream for video, Cloudflare Images for the rare custom-poster override, "store IDs, never URLs, construct at render time" (§8).

**Reviewed, no change.** This was already independently re-derived while reviewing, not just re-read: self-hosting video at 500k-video scale would require building exactly what Cloudflare Stream already provides (adaptive bitrate transcoding, global CDN delivery, thumbnail generation) at real infrastructure cost and operational burden this project has no reason to take on. The one legitimate refinement is vendor-lock-in mitigation, covered next under Cloudflare architecture rather than as its own recommendation, since it's the same underlying concern.

### Cache abstraction

**Current design**: `tube-cache` (not yet built) is planned to own Redis, fragment caching, and CDN purge behind a small API (§16).

**Proposed design**: No new design — but *confirm* now, before Phase 3 writes it, that this API is a real interface (`CacheInterface` or similar) with Redis as the first implementation, not a static-method-only facade hard-coded to Redis. This is a small but consequential decision to lock in before Phase 3's first line of code, not after.

**Benefits**: Keeps Redis swappable (a real operational concern — Redis-compatible alternatives, managed-Redis vendor changes, or a future move to a different eviction/persistence strategy are all realistic multi-year events) and keeps `tube-cache`'s consumers (every other plugin) coded against behavior, not against a specific client library's API.

**Risks**: None meaningful — this is the default good practice for a caching layer, not a novel proposal.

**Migration cost**: Zero now (nothing built yet); the interface is simply how Phase 3 is written from its first commit.

**Adopt now or postpone**: **Adopt now** as an architecture decision; implementation itself happens in Phase 3 as already planned.

### Search abstraction

**Current design**: `wp_tube_search_index` (Phase 7, not built) starts on MySQL `FULLTEXT`, with the plugin structured so the storage backend is swappable to OpenSearch/Elasticsearch later — this was already an explicitly flagged open decision in earlier revisions, never formally settled.

**Proposed design**: Settle it now rather than leave it open into Phase 7: commit to MySQL `FULLTEXT` + indexed taxonomy filtering as the *initial* implementation, behind a query-layer interface (`tube-search`'s own internal contract, per the existing plan) that never leaks MySQL-specific query shapes to its callers (the theme, the REST layer). Explicitly do not stand up Elasticsearch/OpenSearch infrastructure now or in Phase 7's first cut.

**Benefits**: A 500k-row `FULLTEXT` index on title/description with proper taxonomy-ID filtering is genuinely adequate for a catalog this size when relevance ranking requirements are modest (which they are — nothing in this project's scope calls for typo-tolerant fuzzy search or complex faceted ranking). Standing up a dedicated search cluster before there's real query-pattern data from production traffic would be optimizing against a guess.

**Risks**: If real usage later demands better relevance/fuzzy matching than `FULLTEXT` can give, the swap happens then — the risk is fully absorbed by the interface boundary already planned, not a new risk this decision introduces.

**Migration cost**: Zero now. If a swap is needed later, cost is bounded by how well the interface boundary was actually respected when Phase 7 is built — worth stating explicitly as a Phase 7 acceptance criterion, not just an aspiration.

**Adopt now or postpone**: **Adopt now** as a documented decision (settles the open question); infrastructure work stays **postponed** to Phase 7, unchanged from the existing plan.

### Queue abstraction

**Current design**: `wp_tube_import_queue`, a plain DB-table-backed queue polled by a WP-CLI batch worker (§2.3, Phase 5, not built).

**Reviewed, no change.** A message-broker-based queue (Redis Streams, RabbitMQ, SQS) was seriously considered and rejected: the import workload is bursty and batch-oriented (a bulk catalog load, then periodic trickle), not continuous high-frequency real-time messaging — the profile a broker is built for. A DB-table queue is simpler, needs no new infrastructure, and is fully adequate for this shape of work. This is a case where adding infrastructure would be over-engineering, not scaling appropriately — worth recording explicitly since "postponing the interesting queue" can look like an oversight rather than a decision. The one thing genuinely needing Redis (the view-counter write path, §2.2) already correctly uses it for exactly that reason — a real high-frequency workload — showing the distinction is already being applied correctly.

### Event architecture

**Current design**: `Dispatcher` wraps WordPress action hooks synchronously; listeners are directed (in `EVENTS.md`'s design notes) to defer expensive work to the cron/WP-CLI job system rather than doing it inline.

**Reviewed, no change**, after seriously considering whether a generic "deferred job" queue belongs in `tube-core` now, for any listener to push work into. Concluded it isn't needed: the two concrete future consumers already planned (`tube-search`'s index upsert, `tube-cache`'s purge) are both designed as *cheap, targeted, synchronous* reactions (a single-row upsert, a specific cache-key purge) with the *expensive* work (full reindex, bulk purge) already living in the cron-driven batch jobs from §7, not behind the event system at all. Building a generic deferred-job primitive now, with zero concrete consumers who'd need anything beyond "cheap and synchronous," would be exactly the premature abstraction this review is supposed to catch, not prevent.

### Import pipeline

Covered under Queue abstraction above — same conclusion, reviewed and validated as designed.

### Database normalization

**Reviewed, re-confirmed** against 3NF with a specifically skeptical re-read (not reused from the Phase 1 audit). One thing reconsidered from scratch: `wp_tube_video_metadata.schema_version`, currently unused by any code. Kept, not removed — it's a real, standard pattern (per-row schema-version marking for gradual application-level data migrations across 500k+ rows without a blocking `ALTER TABLE`) and costs one `SMALLINT` column; removing it now and re-adding it later, once actually needed, would cost a migration for no benefit. This is exactly the distinction the "no premature optimization" criterion requires: cheap, low-risk, architecturally-justified infrastructure is not the same thing as speculative complexity.

### Database indexing

Covered under Drift Report #5 above — no changes now; two specific future indexes named and explicitly deferred until they have a real consumer.

### Read/write separation

**Current design**: §10 already plans read-replica routing "once traffic requires it," inside `tube-core`'s data-access layer — but that data-access layer doesn't exist as a single seam yet (see the Drift Report #4 finding: `SchemaVersionStore` calls `global $wpdb` directly, five times).

**Proposed design**: This is the direct payoff of the Dependency Injection recommendation above — route all database access through the container's registered connection (today: one connection; later: a read/write pair) instead of `global $wpdb` scattered across repositories. Without this, adding read replicas later means editing every repository that ever called `global $wpdb`; with it, it means changing what the container hands back.

**Benefits**: Turns a future multi-file refactor into a single-file change, at effectively zero cost today since there are only two call sites to fix.

**Risks**: None beyond the DI container's own risk (above) — this is a direct consequence of that decision, not a separate one.

**Migration cost**: Low, same as the DI recommendation — fixes the two existing `global $wpdb` call sites in `SchemaVersionStore` to go through the container instead.

**Adopt now or postpone**: **Adopt now**, bundled with the Dependency Injection recommendation — they're the same piece of work.

### Redis usage

**Current design**: planned for view-counter buffering, object cache, and rate limiting, all in `tube-cache` (§7, §16, not built).

**Reviewed, no change.** All three are genuinely appropriate Redis use cases (ephemeral, high-frequency, tolerant of occasional data loss on Redis restart) — none of them need Redis's persistence guarantees, so no design change is warranted. Already covered by the Cache abstraction recommendation above for the *interface* question.

### Cloudflare architecture

**Current design**: Cloudflare Stream (video), Cloudflare Images (images), Cloudflare CDN (edge cache) — three products from one vendor.

**Proposed design**: When `tube-player` is built (Phase 6), define a `VideoProviderInterface` that `CloudflareStreamProvider` implements, so every consumer (theme templates, `tube-seo`'s schema/embed URLs) calls through the interface rather than against Cloudflare-specific URL construction directly.

**Benefits**: The three-product concentration on one vendor is a real, legitimate lock-in consideration for a system expected to run for years — not because Cloudflare is a poor choice (it isn't), but because "years of maintenance" means vendor pricing, product deprecation, or a future business requirement could all force a change, and the cost of that change should be "swap one implementation" not "rewrite every caller across six plugins."

**Risks**: A small amount of indirection for a single current implementation — the same shape of risk as the Cache abstraction recommendation, and justified the same way.

**Migration cost**: Zero now (nothing built yet) — this is how Phase 6 gets written from its first commit, not a retrofit.

**Adopt now or postpone**: **Adopt now** as an architecture decision for Phase 6 to follow; no code changes today.

### Service boundaries / Domain boundaries / Plugin boundaries

**Reviewed, no change**, after specifically challenging whether `tube-core` owning all data tables (metadata, actors, studios, and — from Phase 4/5 — views, statistics, import queue, watch history) plus the CPT, taxonomies, migrations, and events makes it a God *plugin*, not just a risk inside one file. Concluded this is the correct "core + satellite" shape (comparable to how large WordPress plugin suites centralize schema ownership in one foundation plugin while satellites own presentation/query/ops), *provided* `tube-core` never starts building presentation logic, REST responses for listing/search, or query-building beyond its own tables' CRUD — that would be the actual boundary violation to watch for, not the current concentration of *data* ownership, which is by design and stated explicitly in §4.

### Future microservice compatibility

**Reviewed, and the framing itself challenged.** True microservice extraction (separate deployables, separate databases, network calls) is not a coherent near/medium-term goal for a WordPress plugin suite — it would be a different architecture, not an evolution of this one, and pursuing literal service boundaries now would be over-engineering against a platform choice (WordPress) that was made deliberately. The achievable, valuable version of this goal is what the architecture already does: clean plugin boundaries, no direct cross-plugin table access, communication through documented APIs and events — which is what would make extracting one specific concern (most plausibly search, if it ever outgrows MySQL and a plugin) *less* painful later, without paying for real service infrastructure now. **Recommend documenting this interpretation explicitly** in `ARCHITECTURE.md` so "future microservice compatibility" isn't misread later as a mandate to start building network boundaries — cheap, adopt now, documentation only.

### Horizontal scalability

**Reviewed, no change.** Nothing built so far introduces server-local state (no PHP sessions, no in-process-only caching, no local file writes outside what's already explicitly excluded by the Cloudflare storage decisions) — the application layer as designed can already run as multiple stateless web/PHP-FPM instances behind a load balancer, sharing only MySQL and Redis. No drift from §10's plan found.

### Core Web Vitals impact

**Not yet applicable.** No frontend code exists (`tube-theme` is Phase 8). §8's existing CWV-focused decisions (poster `fetchpriority`, `aspect-ratio` reservation, click-to-load player, no framework/jQuery) are unchanged and were not contradicted by anything built in Phases 0–2.

### SEO impact

**Reviewed, no change.** The one SEO-relevant thing built so far — the URL/rewrite structure from Phase 1 (`/watch/{slug}/`, `/category/{slug}/`, `/tag/{slug}/`) — is clean, human-readable, and keyword-relevant, consistent with §15's plan. `tube-seo` itself doesn't exist yet (Phase 9).

---

## Summary of recommendations requiring a decision

| # | Recommendation | Adopt now / Postpone | Code cost today |
|---|---|---|---|
| 1 | Minimal service container in `tube-core`, replacing per-service `Plugin.php` accessors | Adopt now | Low — 2 existing accessors rewritten |
| 2 | Consolidate database-connection access (fixes the `SchemaVersionStore` vs. `AbstractMigration` `$wpdb` duplication) via the same container | Adopt now | Low — bundled with #1 |
| 3 | Formalize the Repository naming/structure convention in `ARCHITECTURE.md` | Adopt now | Zero — documentation only |
| 4 | Confirm `tube-cache`'s API is a real swappable interface, not a Redis-specific facade | Adopt now (decision); build in Phase 3 as already planned | Zero today |
| 5 | Settle the search-backend question: MySQL `FULLTEXT` first, behind an interface; no search-engine infrastructure yet | Adopt now (decision); infrastructure postponed to Phase 7 | Zero today |
| 6 | `VideoProviderInterface` around Cloudflare Stream, for Phase 6 | Adopt now (decision); build in Phase 6 as already planned | Zero today |
| 7 | Document "future microservice compatibility" as boundary-cleanliness, not literal service extraction | Adopt now | Zero — documentation only |

Recommendations #1 and #2 are the only ones with any code to write today, and they're the same piece of work. Everything else is a documentation decision that shapes how a *future* phase gets built, with no implementation cost now.

**No code has been changed. No `ARCHITECTURE.md` edits have been made.** Awaiting your decision on which of the above to adopt before either happens, per "only after the architecture is finalized may implementation continue."
