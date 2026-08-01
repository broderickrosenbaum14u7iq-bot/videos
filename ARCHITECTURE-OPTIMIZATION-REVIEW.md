# Architecture Optimization Review

Status: **Final for this pass. Supersedes `PRE-PHASE-3-ARCHITECTURE-REVIEW.md`'s conclusions where they differ** (noted explicitly below — that file is kept as a historical record, not deleted, but this document is authoritative). No code changed. Only `ARCHITECTURE.md` and `DEVELOPMENT_RULES.md` are updated as a result of this review, in this same pass.

This is not a compliance check against `ARCHITECTURE.md` — it's a second, harsher pass explicitly instructed to challenge the architecture itself, including my own prior recommendations, from the standpoint of building the fastest, cleanest, most maintainable large-scale WordPress application achievable, not merely a consistent one. Where the first review erred toward adding structure, this pass specifically hunts for over-engineering in that same set of proposals, and separately for the opposite failure (real future pain left under-abstracted).

**The single most important outcome of this pass**: recommendation #1 from the prior review (a generic service container) is **rejected**, not adopted. Building it would have been exactly the kind of "enterprise pattern adopted because it's fashionable" the review was warned against — full reasoning below.

---

## How every abstraction was tested

One rule, applied uniformly: **an interface or abstraction survives only if it has a realistic second implementation.** Not a hypothetical one ("we might swap Redis for Memcached someday") — a realistic one, meaning either (a) a concrete competing implementation this project will plausibly need, or (b) a test fake that will actually be built and used to unit-test real logic, the same way `RecordingHookBus` and `InMemorySchemaVersionRepository` already are in this codebase, not a fake invented to justify the interface after the fact. "Vendor swap someday" was rejected everywhere it was the *only* justification offered; "a test fake for logic that genuinely needs isolating from WordPress/a live service" was accepted every time it was concrete.

---

## Topic-by-topic review

### Dependency Injection
**Current**: constructor injection where an interface exists; `Plugin.php` as composition root with hand-written, typed, lazy-singleton accessor methods (`migration_runner()`, `events()`).

**Challenged and re-confirmed, not changed.** Constructor injection itself is correct and stays. What's rejected is *how* the composition root should evolve as more services are added — see Service Container below.

### Service Container
**Prior recommendation**: introduce a generic container (`set()`/`get()`) in `tube-core`, replacing `Plugin.php`'s per-service accessor methods.

**Re-examined and rejected.** The problem this was meant to solve — `Plugin.php` accumulating accessor methods — was misdiagnosed. That's boilerplate *repetition* (the same five-line "if null, construct, cache, return" pattern copy-pasted), not a single-responsibility violation (every accessor does the *same kind* of thing, not different, unrelated things) — a real God-class risk and a growing-boilerplate risk call for different fixes, and I reached for the wrong one.

A generic container adds a layer of indirection (string- or class-keyed runtime resolution) over what is currently direct, type-safe, IDE-navigable method calls — for a system that will realistically have single digits of services *per plugin* (tube-core has 2 today; even a generous estimate across all six plugins over years lands in the 10–15 range total, never in one container). Containers earn their cost managing dozens-to-hundreds of interdependent services with real autowiring needs (Symfony, Laravel). Introducing one here trades a small, correctly-scoped problem for a new abstraction layer, a new thing every future developer has to learn, and a partial reintroduction of stringly-typed service lookup — the same category of risk `DEVELOPMENT_RULES.md` §6.2 already names as the service-locator anti-pattern, just one level removed. This is precisely "adding an enterprise pattern because it's fashionable," which the review was explicitly told to reject.

**Verdict: Reject.** **Adopt instead**: no new abstraction. If `Plugin.php`'s accessor methods become repetitive enough to bother a future developer, that's solved with a private, in-class memoization helper (`private function once(string $key, callable $factory): mixed`) — deduplicates the boilerplate *inside* `Plugin.php` without exposing any new public, string-keyed API to the rest of the codebase. Not building this now either, since two services don't yet justify even that (see the trigger below).

**Reconsideration trigger** (written into `ARCHITECTURE.md`, not left as a vague "watch it"): if any single plugin's bootstrap class exceeds roughly 6–8 accessor methods, or starts containing real logic instead of pure construction/wiring, that is the point to revisit — not before.

### Repository boundaries
**Prior recommendation**: every dedicated table gets a `{Noun}Repository` *and* a `{Noun}RepositoryInterface`.

**Re-examined and corrected — the interface-for-everything part was itself an over-abstraction.** `SchemaVersionRepositoryInterface` earns its place because `MigrationRunner`'s orchestration logic is genuinely complex and is unit-tested against a fake of it. A repository with no caller complex enough to need isolating from a live database doesn't need a paired interface — it needs a plain class.

**Verdict: Adopt (revised).** Convention: a data-access class for a dedicated table is named `{Noun}Repository`, follows `SchemaVersionStore`'s shape (clear public methods, no `SELECT *`, batched not looped writes — see the new bulk-write rule below). It gets a paired interface **only** when a concrete consumer needs a fake for testing — the same bar every other interface in this codebase already has to clear.

### Database access layer
**Prior recommendation**: consolidate `SchemaVersionStore`'s five independent `global $wpdb;` calls, framed partly as future read/write-replica-routing infrastructure.

**Re-examined and narrowed.** The duplication itself is real and current — confirmed by grep, not assumed: `SchemaVersionStore` has 5 separate `global $wpdb;` statements; `AbstractMigration` already solved the identical problem with one `db(): wpdb` method every migration class reuses. That inconsistency (two different answers to "how do I get the database connection" in the same plugin) is worth fixing on its own, current merits. Framing it as "preparation for read replicas," though, was importing a *hypothetical* future justification into what should be a *present* one — read replicas are explicitly conditional in `ARCHITECTURE.md` §10 ("once traffic requires it"), and designing around that now, before it's needed, is exactly the premature optimization this pass was told to eliminate.

**Verdict: Adopt (narrowed).** A tiny shared trait/base (one `db(): wpdb` method) used by both `AbstractMigration` and `SchemaVersionStore`, justified purely by removing five duplicate lines — nothing else. No read/write-awareness, no connection-pooling logic, no speculative parameters. If read replicas are ever needed, this is a reasonable place to make that change *then* — the trait isn't designed around that possibility, it just doesn't make it harder either. **Not implemented as code in this pass** — this is an architecture decision now, code the first time any commit after this one touches a repository.

### Storage abstraction
**Reviewed, no change.** Cloudflare Stream/Images with "store IDs, never URLs" was independently re-derived, not just re-read, while checking this: self-hosting at 500k-video scale means rebuilding adaptive-bitrate transcoding and global CDN delivery Cloudflare already provides, for no benefit this project needs.

### Cache abstraction
**Prior recommendation**: `CacheInterface`, justified as "keeps Redis swappable."

**Re-examined — right conclusion, wrong reason.** "Might swap Redis for Memcached someday" fails the realistic-second-implementation test on its own. The real, concrete justification is testability: `tube-cache`'s own logic (rate-limiting decisions, purge-key computation) and every *other* plugin's logic that depends on cache behavior need to be unit-tested without a live Redis connection — a fake cache implementation is not hypothetical, it will be built and used the same way `RecordingHookBus` already is.

**Verdict: Adopt, re-justified.** `CacheInterface` (or similarly named) ships with `tube-cache`'s first commit (Phase 3), Redis-backed implementation plus a test fake — justified by testability, vendor-swap optionality noted only as a secondary, non-load-bearing benefit.

### Search abstraction
**Prior recommendation**: MySQL FULLTEXT first, "behind an interface."

**Re-examined and narrowed.** Committing to MySQL FULLTEXT first (not standing up Elasticsearch/OpenSearch infrastructure before there's real query-pattern data to justify it) remains the right call. Mandating a specific interface *now*, for a plugin (`tube-search`) that doesn't exist for another four phases, is deciding a question that isn't Phase 3's to decide — it's Phase 7's, under the same interface-justification rule as everything else.

**Verdict: Adopt (decision only).** MySQL FULLTEXT first is settled. Whether it sits behind an interface is explicitly left to Phase 7's own Drift Report / Regression Review, not pre-decided here.

### Video provider abstraction
**Prior recommendation**: `VideoProviderInterface` around Cloudflare Stream, justified as vendor-lock-in mitigation.

**Re-examined — same correction as Cache abstraction.** Vendor migration is speculative. The realistic justification is that `tube-player`'s embed-markup and poster-URL-construction logic needs to be unit-tested without hitting a live Cloudflare account or needing real Stream UIDs — a test fake, not a vendor swap, is what actually gets built.

**Verdict: Adopt, re-justified.** Same shape as Cache abstraction: interface ships with `tube-player`'s first commit (Phase 6), justified by testability; vendor flexibility is a secondary, non-load-bearing benefit, not the reason it exists.

### Queue architecture / Import pipeline
**Reviewed, no change.** A message-broker queue (Redis Streams, RabbitMQ) was seriously reconsidered here too and rejected again on the same grounds as the first review: the import workload is bursty/batch, not continuous/real-time — the profile a broker is built for, not this project's actual need. The DB-table queue stays the right, simpler choice. Genuinely high-frequency work (view counting) already correctly uses Redis; the distinction is being applied consistently, not as an oversight.

### Event system
**Reviewed, no change**, including re-testing whether a generic deferred-job primitive belongs in `tube-core` now. It doesn't: every concrete planned listener (search index upsert, cache purge) is designed to be cheap and synchronous by construction, with genuinely expensive work already living in cron-driven batch jobs outside the event system entirely. Building a generic "defer this for later" mechanism with zero consumers who need more than "cheap and synchronous" would be inventing the exact premature abstraction this review exists to catch.

### REST API boundaries
**Reviewed, specifically re-checked for hidden coupling.** Every plugin registering routes under a shared `tube/v1` string prefix looked, on first glance, like it might be a form of coupling to `tube-core` "owning" the namespace. It isn't: `register_rest_route()` calls are independent per plugin, don't call into `tube-core`'s code, and don't create a PHP-level dependency — it's a naming convention, the same category as every plugin's tables sharing the `wp_tube_` prefix. No change; noted explicitly because it was checked, not assumed.

### Plugin boundaries
**Reviewed, no change**, after specifically re-litigating whether six plugins is still right or whether it's fragmentation. Concluded six is correct — but on a different basis than "that's what the architecture says": the user has explicitly and repeatedly required independent, separately-testable plugins across this project's instructions, not once but as a standing, reaffirmed requirement (`DEVELOPMENT_RULES.md` §2). That's a firm product requirement, not an incidental design choice inherited from an earlier draft — so it wasn't treated as something to second-guess just because "challenge everything" was the instruction for this pass. What *was* re-checked on its own merits: whether `tube-core` owning all data tables makes it a God-plugin. It doesn't, as long as it stays limited to data/schema/events and never grows presentation, query-building, or REST-response logic that belongs to a satellite plugin — that boundary, not the current concentration of data ownership, is the thing to watch.

### Theme boundaries / Admin architecture
**Not yet applicable** — neither `tube-theme` (Phase 8) nor `tube-admin` (Phase 10) exist yet. The existing boundary rules (theme calls documented template-tag functions only; admin is pure UI over `tube-core`'s API) are already the simplest possible version of these boundaries, not over-engineered, and nothing built so far contradicts them.

### Schema migrations
**Reviewed, re-confirmed.** Reversibility re-verified as still correct (unchanged since `PHASE-1-AUDIT.md`/Phase 2). The self-registration discovery mechanism (vs. literal filesystem scanning) was re-examined again under this harsher pass and re-confirmed as the better design, not just re-asserted from the last review.

### Performance / Memory usage
**Not yet measurable** — no request-serving code exists yet (everything built so far is CPT/taxonomy/migration/event registration, not a hot path). Nothing to optimize or flag prematurely.

### Database indexes
**Reviewed, no change** beyond what `PHASE-1-AUDIT.md` already found. Re-confirmed the two previously-identified future indexes (`cf_status`+`created_at` composite, `video_count` sort index) remain correctly un-added — no consumer exists yet, and adding them now would be exactly the premature optimization this pass was told to eliminate.

### Cloudflare integration / Redis usage / CDN strategy
Covered under Storage, Cache, and Video-provider abstraction above — no separate findings.

### Future multi-server deployment / Horizontal scalability
**Reviewed, no change.** Nothing built introduces server-local state (no PHP sessions, no in-process-only caching, no local file writes). The application layer as designed already supports multiple stateless web/PHP-FPM instances sharing only MySQL and Redis.

### Read/write database separation
**Explicitly reconfirmed as correctly deferred**, and explicitly *decoupled* from the database-access-layer fix above (that fix is justified purely by removing current duplication, not by "getting ready" for replicas). No change to §10's "once traffic requires it" framing.

### Core Web Vitals / SEO architecture
**Not yet applicable** — no frontend (`tube-theme`, Phase 8) or SEO plugin (`tube-seo`, Phase 9) code exists. The URL/rewrite structure already built (Phase 1) was re-checked and remains clean, human-readable, and keyword-relevant.

### Testing architecture
**Reviewed, and upgraded from an open-ended deferral to a concrete trigger.** The decision to skip a full `WP_UnitTestCase` integration suite (Phase 1) remains correct for now — but leaving it open-ended indefinitely, as more phases add more WordPress-hook-wired code with only live/manual verification behind it, is a growing risk that deserves a specific checkpoint rather than a vague "someday." **New rule, written into `ARCHITECTURE.md` and `DEVELOPMENT_RULES.md`**: this must be explicitly reconsidered before Phase 5 (the import pipeline) or Phase 6 (`tube-player`'s rendering logic), whichever is reached first — both introduce substantially more hook-wired logic than unit tests against fakes can verify alone.

---

## What should become MORE abstract / rigorous for 500k+ scale

The review was also told to look for the opposite failure — real future pain currently left too loose:

1. **Bulk relationship writes.** No code exists yet that writes to `wp_tube_video_actors`/`wp_tube_video_studios`, but whoever builds it (Phase 7+) will be tempted to loop a single-row `INSERT` once per actor/studio on every video save — fine at low volume, a real N+1-write problem at 500k videos with several relationships each. **New rule**: any code writing multiple rows to a relationship table in response to one save must use a single multi-row `INSERT`, never a loop of single-row inserts. Written down now, before the temptation exists in real code, costs nothing and prevents a pattern that would otherwise need to be caught in review later.
2. **Testing-architecture trigger**, covered above — this is the same category of finding (a decision correctly deferred, but needing a firmer, written trigger rather than staying open-ended).

Nothing else surfaced a genuine case for *more* abstraction — the specific things one might reflexively over-abstract now (search backend, cache/video-provider interfaces beyond their testability justification, a generic queue) were each checked and found not to need it yet.

---

## Classification summary

| # | Item | Classification |
|---|---|---|
| 1 | Generic service container | **Reject** |
| 1b | In-class memoization helper for `Plugin.php` accessors, only once ~6-8+ accessors exist | Adopt later (trigger-based) |
| 2 | Shared `db(): wpdb` trait (duplication fix only, no read/write framing) | **Adopt immediately** (decision now; code on the next commit that touches a repository) |
| 3 | Repository naming convention, interface only when justified | **Adopt immediately** (documentation) |
| 4 | `CacheInterface`, justified by testability | Adopt later (Phase 3, at `tube-cache`'s first commit) |
| 5 | MySQL FULLTEXT first for search; no interface pre-mandated | **Adopt immediately** (decision); infrastructure/interface choice postponed to Phase 7 |
| 6 | `VideoProviderInterface`, justified by testability | Adopt later (Phase 6, at `tube-player`'s first commit) |
| 7 | "Microservice compatibility" = boundary cleanliness, documented explicitly | **Adopt immediately** (documentation) |
| 8 | Bulk multi-row write convention for relationship tables | **Adopt immediately** (documentation; applies starting Phase 7) |
| 9 | Explicit testing-architecture reconsideration trigger before Phase 5/6 | **Adopt immediately** (documentation) |

---

## Migration Impact Report

**No previous phase becomes invalid.** Checked against every accepted item:

- Phase 0 (tooling/staging/CI/cron scaffold): untouched by anything in this review.
- Phase 1 (`video` CPT, taxonomies, migration framework, four migrations, actor/studio tables): untouched. The database-access-layer fix (item #2) changes *how* `SchemaVersionStore` obtains a `wpdb` instance, not any table schema, not any migration's `up()`/`down()` behavior, not any applied-migration record. When implemented, `wp tube migrate status` must show identical output before and after — that's the acceptance test for that change, not a new migration.
- Phase 2 (event dispatcher, `EventCatalog`, `VideoLifecycleEvents`): untouched. No item in this review changes an event name, a payload shape, or a dispatch trigger.
- `PHASE-1-AUDIT.md`'s findings (the `name_idx` migration, the `SchemaMigrations` namespace rename): untouched and still valid.
- `PRE-PHASE-3-ARCHITECTURE-REVIEW.md`: superseded in its conclusions (the service-container recommendation is now rejected; three other recommendations are re-justified rather than removed) but not factually wrong about anything it observed (the `$wpdb` duplication it found is real and confirmed again here). Kept as a historical record of how the thinking evolved, not deleted or silently overwritten.

**Nothing in this review requires rolling back, re-migrating, or re-verifying any already-committed code.** The one code change it authorizes (item #2) is deliberately **not implemented in this pass** — per the explicit instruction, this commit is documentation only.

---

## Scores

Scale for all three: 0–100, where **100 is the best outcome** (maximally stable / minimally indebted / maximally scalable) — stated explicitly since "Technical Debt Score" is ambiguous about direction otherwise.

### Architecture Stability Score: 85/100

The *core* structural decisions — six independent plugins, `video` as a CPT not a taxonomy, dedicated tables over `wp_postmeta`, event-driven cross-plugin decoupling, the migration framework's shape — have not changed once across two real implementation phases and two full challenge passes (the Enterprise Review and this one). That's the strongest possible evidence of stability: decisions that survive adversarial re-examination without needing to change. Points withheld, not for instability in what's built, but because this is the *second* "reconsider everything" pass in as many turns, and one real reversal (the service container) happened within it — a sign the architecture wasn't fully settled at the moment of initial approval, even though nothing already-implemented has had to be undone. A project with zero reversals across two challenge passes would score higher; one clean reversal caught before it became code, with zero implementation impact, is a good outcome but not a perfect one.

### Technical Debt Score: 92/100

Very low actual debt. The only confirmed duplication in the entire codebase (`SchemaVersionStore`'s `global $wpdb` vs. `AbstractMigration`'s `db()`) is small, fully understood, and has an approved fix not yet applied. The one disclosed test-coverage gap (WordPress-hook-registration methods verified live rather than by `WP_UnitTestCase`) is a deliberate, written-down, now-triggered scoping decision, not an accident — debt that's disclosed, bounded, and has an explicit resolution condition is categorically less costly than debt nobody's tracked. Points withheld only for that one open item and for the fact that the bulk-write convention (item #8) is a rule written ahead of the code it governs, meaning its real test is still in the future.

### Scalability Score: 80/100

The design decisions that matter most at 500k+ videos are sound and, in several cases, were independently re-derived rather than just re-confirmed in this pass: dedicated tables instead of `wp_postmeta`, correct bidirectional indexes on both junction tables, Redis-buffered high-frequency writes vs. a DB-table queue for batch workloads, thin events with cheap synchronous handlers and cron for anything expensive, Cloudflare offload for all media bytes. Score isn't higher because the majority of the code that will actually bear real load at that scale — the search index, the statistics rollup, the import pipeline, the view-recording path, the theme's render path — doesn't exist yet. This score reflects justified confidence in a design that hasn't yet been tested against real data volume or real traffic, which is the honest ceiling for a project three phases into a twelve-phase plan.
