# Architecture Changelog

Durable, ongoing record of every accepted architecture change and why. Append a new dated entry per approved change — never edit or remove a prior entry, even if a later one reverses it (reverse it in a new entry, the same way `PRE-PHASE-3-ARCHITECTURE-REVIEW.md` was superseded rather than deleted). This file is the answer to "why does the architecture say X" for anyone reading `ARCHITECTURE.md` without having read the conversation that produced it.

---

## 2026-08-01 — Post-approval optimization pass (Revision 5)

Source: `ARCHITECTURE-OPTIMIZATION-REVIEW.md` (full reasoning), following an initial pass in `PRE-PHASE-3-ARCHITECTURE-REVIEW.md` (superseded in its conclusions, kept as history). No code changed in this pass — decisions only, applied to `ARCHITECTURE.md` and `DEVELOPMENT_RULES.md`.

### Rejected: generic service container
A container (`set()`/`get()`, replacing `Plugin.php`'s typed accessor methods) was proposed in the first pass and rejected in the second. **Why**: it solved a misdiagnosed problem. `Plugin.php`'s per-service accessors were flagged as a God-class risk, but the actual issue is boilerplate repetition (the same construct-and-cache pattern copy-pasted), not one class doing many unrelated things — a different problem needing a different, smaller fix. A container would have added a runtime-indirection layer, partially reintroducing the service-locator pattern this project explicitly avoids (`DEVELOPMENT_RULES.md` §6.2), to manage a number of services (single digits per plugin) far below the scale where containers earn their cost. **Reconsideration trigger**: if any one plugin's bootstrap class exceeds ~6–8 accessor methods, or starts containing real logic beyond construction/wiring.

### Adopted: shared database-connection accessor
`SchemaVersionStore` calls `global $wpdb;` five separate times; `AbstractMigration` already solved the same problem once with a `db(): wpdb` method every migration reuses. **Why**: real, confirmed (by `grep`) duplication of the same one-line responsibility, independent of any future read/write-replica question — which stays deferred, unchanged, per `ARCHITECTURE.md` §10. **Not implemented as code yet** — decision now, code on the next commit that touches a repository.

### Adopted (revised): Repository convention without mandatory interfaces
Originally proposed as "every table gets a repository and an interface." Revised: a repository is a plain class by default; it earns a paired interface only when a concrete consumer needs a fake for testing, the same bar `SchemaVersionRepositoryInterface` already had to clear for `MigrationRunner`. **Why**: an interface with no realistic second implementation is exactly the kind of unnecessary abstraction this project's own rules (`DEVELOPMENT_RULES.md` §6.6) already prohibit — the original phrasing would have violated a rule already on the books.

### Adopted (re-justified): `CacheInterface` and `VideoProviderInterface`
Both were originally justified as "keeps the vendor swappable." **Why re-justified, not rejected**: vendor-swap speculation alone doesn't clear the realistic-second-implementation bar. The real, concrete justification is testability — a fake cache and a fake video provider will actually be built and used to unit-test dependent logic without a live Redis connection or Cloudflare account, the same pattern already proven by `RecordingHookBus` and `InMemorySchemaVersionRepository`. Vendor flexibility remains a real but secondary, non-load-bearing benefit. `CacheInterface` ships with `tube-cache`'s first commit (Phase 3); `VideoProviderInterface` ships with `tube-player`'s first commit (Phase 6).

### Adopted: search backend decision settled
MySQL `FULLTEXT` + indexed taxonomy filtering is the committed first implementation for `tube-search` (Phase 7) — resolves a question left open since an earlier architecture revision. Whether that query layer sits behind an interface is explicitly *not* pre-decided here; Phase 7 decides it under the same interface-justification rule as everything else, when there's an actual consumer to justify it either way.

### Adopted: "future microservice compatibility" clarified
Documented explicitly as boundary cleanliness (own data, documented APIs/events, no direct cross-plugin table access) enabling easier future extraction of one specific concern if it's ever needed — not a mandate to build literal service/network boundaries now. **Why**: prevents a future reader from misinterpreting this phrase as a call to start building infrastructure this WordPress-plugin architecture was never meant to need yet.

### Adopted: bulk multi-row write convention
Any code writing multiple rows to a relationship table (`wp_tube_video_actors`, `wp_tube_video_studios`, and any future equivalent) in response to a single save must use one multi-row `INSERT`, never a loop of single-row inserts. **Why**: no code doing this exists yet (Phase 7+), but the naive loop version is the obvious first instinct and would be a real N+1-write problem at 500k videos with several relationships each — cheaper to write the rule down now than to catch it in review later.

### Adopted: testing-architecture reconsideration trigger
The Phase 1 decision to defer a full `WP_UnitTestCase` integration suite remains correct for now, but was upgraded from an open-ended deferral to a concrete checkpoint: **must be explicitly reconsidered before Phase 5 (import pipeline) or Phase 6 (`tube-player`), whichever comes first** — both introduce substantially more WordPress-hook-wired logic than unit tests against fakes alone can verify.

### Reaffirmed without change (explicitly re-examined, not just carried over)
Six independent plugins (re-examined on the basis of the user's own repeated, explicit testability requirement, not just "that's what was already decided"); DB-table-backed import queue over a message broker; the event system's synchronous-and-cheap-by-design shape over a generic deferred-job primitive; `tube/v1` REST namespace sharing (confirmed to be a naming convention, not runtime coupling); Cloudflare storage/CDN strategy; read/write database separation staying deferred; horizontal scalability posture (no server-local state introduced so far).

---

## 2026-08-05 — Phase 9 scope reconciliation (Phase 8's `tube-seo` pull-forward)

Source: `PHASE-8.md`. Not a technical architecture change under `DEVELOPMENT_RULES.md` §8 — no database schema, event catalog, REST versioning, caching strategy, or URL structure changed. This entry documents a §12 phase-table correction only: bringing the roadmap's stated phase ownership back in line with a scope decision already made and delivered during Phase 8's own implementation, so the table doesn't show the same deliverable claimed by two phases at once.

**What happened**: Phase 8's kickoff instruction included a full SEO deliverable list (title/meta description/canonical/robots/OpenGraph/Twitter Cards/JSON-LD/pagination metadata) that duplicated §12's original Phase 9 row (`tube-seo`: schema, meta tags, sitemap generation) almost entirely. Flagged back to the user before any code was written; the user chose explicitly to build `tube-seo` now rather than defer it (`PHASE-8.md` §1). Phase 8 then delivered everything from that list against a real `tube-seo` plugin (`SeoHead`, `PageMetaBuilder`, `VideoObjectBuilder`, `BreadcrumbListBuilder`, `CollectionPageBuilder`, `tube_seo_head()`).

**What was NOT pulled forward**: video XML sitemap generation (`wp tube-seo sitemap:generate`, §7's hourly cron row) — never part of Phase 8's SEO deliverable list, and not built. This is now Phase 9's entire remaining scope.

**§12 updated accordingly**: Phase 8's row now names the `tube-seo` deliverables it actually shipped; Phase 9's row is narrowed to sitemap generation only, with both rows cross-referencing this entry and `PHASE-8.md`. No other section of `ARCHITECTURE.md` needed a change — §4's plugin-purpose tree (`tube-seo: meta/schema/sitemap`), §6's event table (`video.published` → `tube-seo (sitemap flag)`), and §7's cron table (`wp tube-seo sitemap:generate`) all already described only the sitemap piece as still-future work, or described `tube-seo`'s purpose in phase-independent terms; none made a phase-ownership claim Phase 8 had already invalidated.

**Phases 10–12 are unchanged and not renumbered.** Considered and rejected: renumbering would ripple into every other document and commit that already refers to "Phase 10" (`tube-admin`'s own plugin header, prior `PHASE-X.md` files, this changelog's own history) for a purely cosmetic gain, and this project's own convention is to layer corrections on top of history (`PHASE-1-AUDIT.md`) rather than rewrite it. A phase whose numbered scope shrinks because an earlier phase absorbed part of it is not the same situation as a phase that needs renumbering — there is no gap, overlap, or ordering problem in Phases 10–12 for renumbering to fix.

---

## 2026-08-07 — Phase 13 added to §12 (Production UI, user-commissioned)

Source: `PHASE-13.md`. Not a technical architecture change under `DEVELOPMENT_RULES.md` §8 — no database schema, event catalog, REST versioning, caching strategy, or URL structure changed; every new capability uses a pattern already established by an earlier phase (a new template tag wrapping an already-existing repository method, per Phase 8's own precedent for closing a "prerequisite gap" the presentation layer needed; a new WordPress Page template for the actor/studio directories, the exact mechanism Phase 8 already used for Trending/Most-Viewed/Latest; `ImageSize`'s enum gaining one more case, the same extensibility the enum's own docblock already anticipated). This entry documents a §12 phase-table **append**, not a correction — Phase 12 was the project's final originally-planned phase (`RELEASE.md`: "this is the final planned phase. No Phase 13 begins without a new, explicit instruction to do so"); the user gave that explicit instruction directly, commissioning a new Phase 13 outside the original 0–12 roadmap.

**What was added**: `tube-core` gained `ActorRepository::find_many()`/`StudioRepository::find_many()` (mechanically copying `VideoMetadataRepository::find_many()`'s already-reviewed batched-query + memoization pattern) and 6 new template tags exposing them plus the already-existing `list_all()`/`count_all()` methods. `tube-player` gained `ImageSize::Avatar`, a new `ProfileImageHtmlRenderer` class (a sibling to, not a variant of, `ImageHtmlRenderer` — no Stream-thumbnail fallback branch exists for an actor/studio photo), and `tube_player_get_profile_image_html()`. `tube-theme` got a full visual rebuild (dark theme, hero, mega menu, infinite scroll, actor/studio pages, modern search) — hand-written CSS/JS, no page builder, no CSS framework, no build tooling, consistent with this project's zero-build-tooling posture everywhere else.

**Why this stays out of the ADR process**: none of the four §8 trigger conditions apply (no benchmark proved the frozen design insufficient, no production issue forced a change, and the "new functional requirement" — a real UI — was met entirely with already-established patterns, never a reason to touch a frozen decision). The one operational dependency this phase introduces (a new `avatar` Cloudflare Images variant needs configuring before production launch) is flagged in `PHASE-13.md` §10 as an operational task, not an architecture question.

**§12 updated accordingly**: a new row 13 added, explicitly marked as user-commissioned and outside the table's original 0–12 scope, cross-referencing `PHASE-13.md`. No other section of `ARCHITECTURE.md` needed a change — §5/§8's template-tag/image-rendering descriptions already describe the *pattern* these additions follow, not an exhaustive list of every tag that will ever exist; §15's URL table is unchanged (the actor/studio directory pages use the existing "ordinary WordPress Page" mechanism, not a new rewrite rule).
