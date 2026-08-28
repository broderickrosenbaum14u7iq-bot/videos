# Architecture Changelog

Durable, ongoing record of every accepted architecture change and why. Append a new dated entry per approved change — never edit or remove a prior entry, even if a later one reverses it (reverse it in a new entry, the same way `PRE-PHASE-3-ARCHITECTURE-REVIEW.md` was superseded rather than deleted). This file is the answer to "why does the architecture say X" for anyone reading `ARCHITECTURE.md` without having read the conversation that produced it.

---

## 2026-08-24 — ADR-0001: WordPress Media Library for poster/OG-image overrides (frozen decision reversal)

Source: `adr/0001-media-library-poster-images.md`. This **is** a technical architecture change under `DEVELOPMENT_RULES.md` §8 — it reverses the image half of `ARCHITECTURE_FREEZE.md`'s Frozen Decision #5 ("no video/image bytes are ever stored on the WordPress server") for the admin-selected poster/OG-image override path, and resolves `ARCHITECTURE.md` §13's Open Decision #5 the opposite way from Revision 5's original recommendation.

**Trigger**: new functional requirement, given directly by the project owner (2026-08-24), overriding §8.2/§13's original Cloudflare-Images recommendation now that the confirmed production deployment target (`RELEASE.md`: 3,000–10,000 videos on a single VPS) makes the original "millions of physical files" scale concern inapplicable to the override path specifically.

**What changed**: `wp_tube_video_metadata.poster_image_id`/`og_image_id` now store WordPress Media Library attachment IDs, not Cloudflare Images IDs. `tube-admin`'s poster/OG-image picker uses WordPress's native media modal (upload-from-computer + select-existing-library-item) instead of a custom upload-to-Cloudflare-Images flow; `CloudflareImagesUploader`/`PosterUploadService`/`ImageUploaderInterface`/`ImageUploadException` (Phase 10) are removed. `tube-player`'s `ImageHtmlRenderer` resolves an override via `wp_get_attachment_image_url()`/`wp_get_attachment_image_srcset()` instead of `CloudflareImagesUrlBuilder`. The default (no-override) Cloudflare Stream thumbnail-extraction path is unchanged — confirmed explicitly with the project owner that Stream extraction remains the fallback for any video with no Media Library poster selected, not removed outright. Actor/studio profile photos (Phase 13, a separate feature) are explicitly out of scope and remain on Cloudflare Images unchanged.

A pre-existing gap found during this change's investigation — `tube-seo`'s `SeoHead`/`SitemapGenerator`/`VideoObjectBuilder` never actually honored the `og_image_id` override at all, always using the Stream thumbnail directly, independent of whatever storage backend was behind the override — was fixed in the same change so all consumers of the override are consistent, per this change's own "don't patch one renderer without updating every caller" requirement.

**Migration**: `Migration010SeparateLegacyCloudflareImageIds` (`tube-core`) renames the existing `poster_image_id`/`og_image_id` columns to `legacy_cf_poster_image_id`/`legacy_cf_og_image_id` (preserving, not discarding, any value that might exist in an environment this session could not inspect — confirmed empty in every environment actually checked) and adds fresh `poster_image_id`/`og_image_id` columns. Full detail, rollback, and impact analysis in the ADR itself.

**Also shipped in the same change** (an additive functional requirement, not itself a frozen-decision reversal): `VideoMetadataRepositoryInterface::update_stream_uid()` and a manually-editable Cloudflare Stream UID field in `tube-admin`'s video edit screen, with duplicate-UID validation. No frozen decision required the Stream UID to be immutable after creation; this is new capability, not a reversal.

---

## 2026-08-28 — ADR-0002/0003/0004: three plugins added, filed retroactively (frozen decision extension)

Source: `adr/0002-tube-members-plugin.md`, `adr/0003-tube-comments-plugin.md`, `adr/0004-tube-ads-plugin.md`. This **is** a technical architecture change under `DEVELOPMENT_RULES.md` §8 — it extends `ARCHITECTURE_FREEZE.md`'s Frozen Decision #1 from six plugins to nine.

**Governance note, stated plainly**: these three plugins (`tube-members`, `tube-comments`, `tube-ads`) were built and shipped during the same run of feature-development work that produced the Phase 13 production UI and the subsequent theme redesign, without an ADR at the time — a direct process violation of §8's "no exceptions" requirement. This was found by an independent Release Readiness Audit on 2026-08-28 (recorded as that audit's BLOCKER-2) and is being corrected here, as part of that audit's P0 remediation, by filing the ADRs that should have existed from the start. The three ADRs are written truthfully against the code as it actually exists today, not reconstructed as if written first — see each ADR's own "Retroactive filing" section.

**Trigger** (all three): new functional requirement — none of accounts/auth, public comments, or ad monetization exist anywhere in the frozen Phase 0–13 architecture, and none could have been anticipated at freeze time (which was scoped entirely to content delivery and discovery). No benchmark or production incident is involved in any of the three.

**What changed**: `tube-members` (WordPress-native `wp_users`/`wp_usermeta`-backed accounts, email/password + Google OAuth, no custom schema); `tube-comments` (comments/likes/reports on five self-owned tables, migrated through `tube-core`'s existing shared migration-runner registration point — the one genuine cross-plugin touchpoint among the three, and an already-frozen mechanism per Frozen Decision #7, not a new one); `tube-ads` (VAST ad placements, `wp_options`-only, no schema, no cross-plugin dependency at all). Full detail, alternatives considered, migration/rollback plans, and impact analysis in each ADR.

**Verified at filing time**: none of the six originally-frozen plugins required modification for any of the three additions (confirmed by repo-wide dependency grep — no new plugin holds a runtime `use` dependency on another's internals, only `tube-comments`' documented migration-runner registration call into `tube-core`'s public accessor). All three pass their own test suites (`tube-members` 24/24, `tube-comments` 39/39, `tube-ads` 17/17 unit tests) with 0 PHPCS/PHPStan errors as of the same 2026-08-28 audit.

**Also recorded in the same audit, tracked separately, not part of this ADR**: two security defects specific to `tube-members`' auth flow (OAuth account-linking, login rate-limiter fail-open behavior) and a data-integrity gap in `tube-comments`/`tube-core` (no cascade cleanup on video delete) — all being remediated as their own separately-tracked P0/P1 items with their own commits, not folded into this governance filing.

---

## 2026-08-25 — ADR-0001 addendum: Cloudflare Stream thumbnail default removed

Source: `adr/0001-media-library-poster-images.md`'s "Addendum (2026-08-25)" section. Further reverses `ARCHITECTURE_FREEZE.md` Frozen Decision #5's image half, on top of ADR-0001's original (2026-08-24) reversal.

**Trigger**: live manual browser testing showed videos with no WordPress Media Library poster set were still rendering a Cloudflare Stream–extracted thumbnail — correct per ADR-0001's original decision (the default path was explicitly left unchanged), but the project owner explicitly reversed that too (2026-08-25): no Cloudflare Stream thumbnail may ever be used as a poster image; a video with no Media Library poster renders no image (theme placeholder), not a substitute.

**What changed**: `ImageHtmlRenderer` (`tube-player`) no longer depends on `VideoProviderInterface`/`CloudflareStreamProvider` at all — its Cloudflare Stream fallback branch is removed outright, not merely deprioritized. `resolve_urls()`/`render()` now return `null`/`''` (no image) whenever `poster_image_id`/`og_image_id` is unset or doesn't resolve to a real attachment; a broken/stale attachment reference now behaves identically to "no override set," not a graceful-degrade-to-Stream case. This is the single shared resolution point every caller (`tube_player_get_image_html()`, the click-to-load player's poster, `tube-seo`'s `SeoHead`/`SitemapGenerator`/`VideoObjectBuilder`) already went through, so removing the fallback there closes it everywhere at once. `VideoProviderInterface::thumbnail_url()`/`CloudflareStreamProvider::thumbnail_url()` themselves are not deleted — no application caller remains, but the capability stays as part of the frozen §19.5 vendor-swap boundary, still unit-tested directly.

**Consequence for `tube-seo`**: `SitemapGenerator::build_entries()` now omits a video from the XML sitemap entirely if it has no resolvable OG-image (Google's video sitemap protocol requires a real `<video:thumbnail_loc>` — the same "not ready to publish" treatment already applied to a video with no Stream metadata row at all). `VideoObjectBuilder::build()`'s `thumbnailUrl` and `SeoHead`'s `og:image`/`twitter:image` are omitted, not fabricated as an empty string, under the same condition — mirroring how a genuinely-unknown `duration`/`video:duration` is already omitted rather than fabricated as `0`.

**Migration**: none — application code only, no schema change.

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
