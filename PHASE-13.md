# Phase 13 — Production UI

Status: **Complete.** A full visual redesign of `tube-theme` (dark theme, hero banner, mega menu, video-card hover effects, infinite scroll, actor/studio pages, a modern search page), built entirely as hand-written CSS/JS — no page builder, no Bootstrap/Tailwind, no build step — plus a small, additive set of new template tags on `tube-core`/`tube-player` that the presentation layer genuinely needed and didn't have, following the exact "prerequisite gap" precedent `PHASE-8.md` §1 already established. This was a user-commissioned phase (not part of `ARCHITECTURE.md` §12's original 0–12 table, which ended at Phase 12's final release) — the plan was researched, clarified with the user across three rounds of explicit decisions, and approved before any code was written; the approved plan is preserved verbatim in this session's plan-mode record.

---

## 1. Architecture Drift Report

Confirmed clean against the codebase as Phase 12 left it (re-read `ARCHITECTURE.md`, `DEVELOPMENT_RULES.md`, and the actual plugin/theme source before writing any Phase 13 code), and re-confirmed after:

1. **No circular dependencies** — `tube-player`'s new `ProfileImageHtmlRenderer` depends only on its own existing `CloudflareImagesUrlBuilder`; `tube-core`'s new repository methods add no outbound dependency on any other plugin. No plugin's `composer.json` changed.
2. **No service locator pattern** — every new accessor (`Tube_Player\Plugin::profile_image_renderer()`) follows the existing construct-or-return-cached composition-root shape; no new class reaches into `Plugin::instance()` from unrelated logic.
3. **No hidden singleton growth** — no new static state anywhere outside the two documented request-lifetime caches (`ActorRepository`/`StudioRepository`'s new `$cache` property, matching `VideoMetadataRepository`'s existing pattern exactly; `inc/template-functions.php`'s two `static` locals, both documented, both request-scoped).
4. **No God classes** — `Tube_Player\Plugin`: 4 accessors before this phase, 5 after (`profile_image_renderer()`) — stays thin/symmetric, well under the §19.2 reconsideration trigger.
5. **No duplicated abstractions** — `template-parts/video-grid.php` consolidates what was 7 near-identical copies of "prime + grid + pagination" into one; `template-parts/profile-avatar.php` consolidates what would otherwise have been 3 copies of "photo or placeholder icon" markup (`profile-header.php`, `page-templates/actors.php`, `studios.php`). `ProfileImageHtmlRenderer` is a genuinely new class, not a near-duplicate of `ImageHtmlRenderer` — it has no Stream-thumbnail fallback branch to duplicate, only the Cloudflare Images override path, reused via the shared `CloudflareImagesUrlBuilder` collaborator rather than copy-pasted.
6. **No unnecessary interfaces** — no new interfaces introduced this phase.
7. **No premature optimization** — `tube_theme_prime_video_grid()`'s actor/studio priming addition is justified by the same real, already-precedented N+1 concern Phase 11 fixed for video metadata (§19.2's actual-need bar), not speculative.
8. **No plugin-boundary violations** — the theme still calls only documented `tube_*` template tags and native WordPress core functions (`get_terms()`, `get_posts()`, `get_permalink()`, `get_page_template_slug()`); no `$wpdb` access anywhere in the theme. `tube-player`'s new `ProfileImageHtmlRenderer` reuses `tube-player`'s own `CloudflareImagesUrlBuilder`, never reaching into `tube-core`'s internals for image construction.

**Result: clean**, both before and after this phase's work.

---

## 2. What was built

### 2.1 tube-core: additive actor/studio repository + template-tag layer

- **`ActorRepository::find_many()`/`StudioRepository::find_many()`** — new, mechanically copying `VideoMetadataRepository::find_many()`'s already-reviewed batched-`WHERE id IN (...)` + request-lifetime-memoization pattern verbatim. `find()` on both repositories now shares that same cache (previously unmemoized).
- **6 new template tags** (`includes/template-tags.php`): `tube_core_list_actors()`/`_count_actors()`/`_list_studios()`/`_count_studios()` (thin wrappers over already-existing `list_all()`/`count_all()`, previously called only from `tube-admin`) and `tube_core_get_actors()`/`_get_studios()` (thin wrappers over the new `find_many()`).

### 2.2 tube-player: actor/studio photo rendering

- **`ImageSize::Avatar`** — new square (400×400) size preset; requires a matching `avatar` variant configured in the Cloudflare Images dashboard before production launch (an operational dependency, not just code — flagged to whoever owns that account).
- **`ProfileImageHtmlRenderer`** (new class) — renders an actor/studio photo `<img>` from a Cloudflare Images ID, with no Stream-thumbnail fallback (none exists for a photo). Returns `''` gracefully for a null ID or an unconfigured `CloudflareImagesUrlBuilder`.
- **`Tube_Player\Plugin::profile_image_renderer()`** — new accessor, same lazy-singleton shape as every other one on this class.
- **`tube_player_get_profile_image_html()`** — new template tag.

### 2.3 tube-theme: the Phase 13-proper deliverable

- **Design system**: `assets/css/tube-theme.css`, full rewrite (was 115 lines, now a complete dark-theme design system — tokens, reset, layout primitives, header/mega-menu/mobile-nav, hero, video-grid/card, listing/pagination/breadcrumbs/empty-state, category tiles, actor/studio profile + directory, search, single-video, footer, responsive breakpoints at 640/900/1200px).
- **`assets/js/tube-theme.js`**: extended (was 28 lines) — mobile off-canvas nav toggle, mega-menu touch-tap toggle, and infinite-scroll (`IntersectionObserver` + `fetch` + `DOMParser` + `history.pushState`, per `ARCHITECTURE.md` §15.2's already-specified progressive-enhancement design). The original search-form-redirect handler is unchanged.
- **New shared template-parts**: `video-grid.php` (consolidates prime+grid+pagination, the markup contract infinite scroll depends on), `hero.php`, `mega-menu.php`, `profile-header.php`, `profile-avatar.php`.
- **New page templates**: `page-templates/actors.php`, `page-templates/studios.php` (directory listings, same "ordinary WordPress Page, no new rewrite rule" precedent as Phase 8's Trending/Most-Viewed/Latest).
- **Rewritten**: `header.php` (mega menu, nav, search, mobile toggle), `footer.php` (multi-column), `front-page.php` (hero + trending/most-viewed/latest rows + categories, each with a "View all" link), `template-parts/video-card.php` (hover overlay, duration badge, actor/studio "starring" badge), `template-parts/archive-listing.php` (delegates to `video-grid.php`; new optional `heading_tag` arg so actor/studio pages don't get two `<h1>`s), `search.php` (delegates to `video-grid.php`, keeps its existing no-total-count pagination heuristic), `archive-actor.php`/`archive-studio.php` (profile header + `heading_tag: 'h2'`), `single-video.php` (related-videos section wrapped in `.section`), `404.php` (empty-state styling), `template-parts/pagination.php`/`breadcrumbs.php` (added `aria-current="page"`).
- **New helpers** (`inc/template-functions.php`): `tube_theme_page_template_url()`/`tube_theme_resolve_page_template_urls()` (bulk-resolves every custom-templated Page's URL in one query, memoized for the request — see §5), `tube_theme_format_duration()`, and `tube_theme_prime_video_grid()` extended to also prime the actor/studio repositories' caches.

---

## 3. Design decisions

Six decisions were made explicitly with the user before implementation began (full context in the approved plan):

1. **Minimal additive plugin-layer exposure approved** — new template tags on already-existing repository methods, plus `find_many()`/`ProfileImageHtmlRenderer` as small, precedent-copying new code. Not a frozen-architecture change (no new pattern, no new abstraction beyond what §19.1-style justification already covers).
2. **Hero banner = auto**, from `tube_search_trending(1)[0]` — no new data model. Implemented by calling `tube_search_trending(12)` once and slicing `[0]` for the hero, reusing it for the Trending row too, rather than a second cached-query call.
3. **Dark theme only** — no toggle, no `prefers-color-scheme` branching.
4. **Infinite scroll scoped to archives + search only** — the only pages with real, offset-aware `ArchivePage` pagination. Homepage and Trending/Most-Viewed/Latest keep fixed-count rows with "View all" links; extending infinite scroll there would need new `tube-search` pagination/caching logic and brushes the frozen cache-invalidation design (§16) — correctly out of scope.
5. **New `ImageSize::Avatar` (square)**, not a reuse of `GridCard` cropped — a real, if small, operational dependency (Cloudflare dashboard variant) accepted knowingly.
6. **Video-card "starring" badges approved**, including the `find_many()` work needed to resolve them without an N+1.

Two further design points emerged during implementation, not pre-planned:

- **`archive-listing.php`'s `heading_tag` argument.** Adding `profile-header.php`'s own `<h1>` (the actor/studio's name) to the actor/studio archive pages would have produced two `<h1>`s per page alongside `archive-listing.php`'s own title heading — a real accessibility/SEO correctness issue caught while wiring the two together, not by any linter. Fixed by giving `archive-listing.php` an optional `heading_tag` arg (`h1` default, unchanged for category/tag pages; `h2` for actor/studio pages, which pass `title: 'Videos'` as a section label under the real page `<h1>`).
- **`template-parts/profile-avatar.php` extraction.** The "photo or placeholder icon" markup was about to be written three times (`profile-header.php`, `actors.php`, `studios.php`); extracted into one shared partial instead — also resolved a `Generic.Files.LineLength` phpcs warning that the triplicated version had at one nesting depth, for free.

---

## 4. Backward compatibility with Phases 0–12

Verified live, not assumed, at every step of implementation (not just once at the end):

- `wp tube migrate status`: all 10 pre-existing migrations unchanged — this phase adds no new tables or migrations.
- All 6 plugins show `active` with zero fatals, checked after every commit's worth of changes, not just at the end.
- Every existing template tag's signature is unchanged; every new one is additive.
- `phpcs` exit `0` and `phpstan analyse` (level `max`, whole repo, 253 files by the end) `[OK] No errors`, re-checked after every file group.

---

## 5. Real findings from this phase's own Implementation Review

Per `DEVELOPMENT_RULES.md` §7, run continuously (every meaningful group of changes, not just once at the end) rather than as a single end-of-phase pass, given the size of this phase:

1. **Fixed — a genuine duplicate query.** `header.php` originally called `get_terms()` twice for the same conceptual category list (once via `mega-menu.php`, once again for the mobile-nav panel, with two different `number` limits, so WordPress's own in-request term-query cache couldn't dedupe them). Fixed by fetching once in `header.php` and passing the result into both call sites.
2. **Fixed — 5 queries collapsed to 1.** `tube_theme_page_template_url()` originally issued one `get_posts()` per template name (up to 5 per request: Trending/Most-Viewed/Latest/Actors/Studios, called from both `header.php` and `footer.php`). Fixed by resolving every published Page's assigned template into one map via a single bulk `get_posts(['meta_compare' => 'EXISTS'])` query, memoized for the request. Verified live (`BENCHMARKS.md`'s Phase 13 section): took a category-archive page from 27 down to 21–23 queries, and the homepage's own page-generation time held essentially flat against Phase 12 despite substantially more per-request work.
3. **Fixed — 9 PHPStan findings, all real, none suppressed.** Several `is_string()`/`instanceof` checks the code initially carried were provably redundant once PHPStan traced the actual type flow (`get_terms()`'s narrowed return type after an `is_array()` guard, `array_filter()`'s null-check-closure narrowing, a `WP_Post` object freshly returned by `get_posts()`) — removed rather than suppressed, since PHPStan's own instruction is explicit: fix the underlying cause, never `@phpstan-ignore`. Two required a real type-narrowing annotation on an under-typed `static` local variable (`inc/template-functions.php`'s two caches) rather than a redundant runtime check.
4. **Accepted, not a finding — the footer's own separate, smaller categories query.** `footer.php` fetches its own `get_terms(['number' => 6, ...])`, distinct from `header.php`'s `number => 12` list — a deliberate, small, additional fixed query for a genuinely different display need (footer shows fewer items), not a duplicate of the same data with the same shape.
5. **Security**: every new `echo` in every new/touched template goes through `esc_html()`/`esc_attr()`/`esc_url()`; the pre-escaped-HTML exceptions (`tube_player_get_profile_image_html()`'s and `tube_player_get_image_html()`'s own output) carry documented `phpcs:ignore` comments citing the same escaping guarantee Phase 6 already established, not blind suppressions. No new REST routes, no new capability/nonce surface (no new admin write actions this phase). No secrets or PII in any new cache value.
6. **Race conditions**: `tube_theme_page_template_url()`'s request-lifetime map and `ActorRepository`/`StudioRepository`'s new caches are read-mostly, request-scoped, and never shared across requests — no new concurrency risk.
7. **Dead code**: none found — every new function/class's real caller checked explicitly (the phase's own commit sequencing, prerequisite-then-consumer within the same phase, was chosen specifically to avoid the zero-caller "unnecessary abstraction" state a split phase would have risked — see the approved plan's §2 reasoning).

---

## 6. Live verification

All performed against the real staging stack, not assumed:

- **Every page type** curled directly for `200`/zero fatals: homepage (empty state and hero-populated states, both verified with real seeded/cleaned-up data), category archive (both a single-page and a real 2-page/25-video case, built specifically to exercise infinite scroll's HTML-fetch target), search (a real match and a real no-match case), actor archive, studio archive, an unknown actor slug (real `404`), a generic `404`, single-video page.
- **Infinite scroll's real contract**: confirmed the `rel="next"`/`rel="prev"` links, `data-tube-video-grid`/`data-tube-pagination`/`data-tube-infinite-scroll` markers, and — critically — that page 2's `<title>` and `rel="canonical"` are genuinely distinct from page 1's and self-canonical to page 2's own URL, exactly matching `ARCHITECTURE.md` §15.2's frozen policy (no "AJAX-only" page state). The client-side JS itself (`fetch`/`DOMParser`/`pushState`) was syntax-validated (`node --check`, zero errors) and carefully reviewed, but **could not be interaction-tested in a real browser** — no browser-automation tool was available in this environment. This is a disclosed limitation, not a skipped check; see §10.
- **Actor/studio pages**: seeded a real actor with a bio and a real studio with a description (no photo, to also verify the placeholder-icon path), assigned both to a video, confirmed the profile header, the "starring" badge on the video card, and the directory pages all render correctly — then cleaned up every seeded row (actor, studio, relationship rows) and re-confirmed a clean baseline state and passing suites afterward, the same "seed → verify → clean up → re-verify" discipline Phase 8 established.
- **SQL query counts**: measured via the same temporary `SAVEQUERIES`/mu-plugin instrumentation Phase 8 used (§5 there), removed after use — no trace left in the repository or the live database. Full numbers and analysis in `BENCHMARKS.md`'s Phase 13 section.
- **CSS**: brace-balance-checked (148 `{` / 148 `}`); reviewed for the documented dark palette (`#0b0e14` background, `#ff3d5a` accent) applied consistently, and for the three documented responsive breakpoints (640/900/1200px) with a mobile-first base. **Could not be visually screenshotted** in this environment — no browser tool available. Disclosed in §10.

---

## 7. Benchmark Report

Full results and analysis in `BENCHMARKS.md`'s new "Phase 13" section. Summary: every metric `ops/benchmark/run.sh` itself measures (migration status, event dispatch, REST core endpoint, cache operations, import throughput) is unchanged from Phase 12 within normal run-to-run noise, as expected for theme-only + additive-template-tag work. Page-rendering SQL query count — the metric this phase's actual work could plausibly have regressed — was measured directly, found to carry two real, fixable inefficiencies (§5 above), and both were fixed before this commit. No regression remains; the homepage's page-generation time is essentially flat against Phase 12 despite doing substantially more per request.

---

## 8. Automated tests

### 8.1 Unit tests

**1 new test**: `ImageSizeTest::test_avatar_is_square()` (tube-player). `ImageSizeTest::test_every_size_has_positive_dimensions()` already covers the new `Avatar` case automatically (iterates `ImageSize::cases()`); `test_tryfrom_resolves_every_documented_size_string()` extended to assert `avatar` round-trips. `ProfileImageHtmlRenderer` is verified via integration tests and live checks only, not unit-tested — the same split every other thin, WordPress-coupled real-output adapter in this project uses (`ImageHtmlRenderer`'s own precedent).

Total: **166/166** passing (65 tube-core + 32 tube-cache + 10 tube-player + 23 tube-search + 27 tube-seo + 9 tube-admin), up from Phase 12's 165.

### 8.2 Integration tests (real WordPress + MySQL + Redis, inside the `wpcli` container)

**8 new tests**: `ActorStudioIntegrationTest` (+3, tube-core — `find_many()` batching/priming for both repositories, plus the 6 new template tags reaching real repositories), `TemplateTagsIntegrationTest` (+5, tube-player — `tube_player_get_profile_image_html()`'s null-ID/unrecognized-size/unconfigured-builder paths via the real template tag, plus `ProfileImageHtmlRenderer`'s happy-path markup and null-ID-even-when-configured cases via direct construction, independent of this environment's own Cloudflare Images configuration).

Total: **92/92** passing (41 tube-core + 12 tube-player + 23 tube-search + 14 tube-seo + 2 tube-admin), up from Phase 12's 84. `tube-cache` has no integration suite (unchanged, pre-existing).

---

## 9. Verification summary

| Check | Result |
|---|---|
| `phpcs` (whole repo) | Exit `0` |
| `phpstan analyse --memory-limit=1G` (whole repo, level `max`, 253 files) | Exit `0`, `[OK] No errors` |
| `phpunit` (all 6 plugins' unit suites) | 166/166 passing |
| `phpunit -c phpunit-integration.xml.dist` (5 plugins with a suite) | 92/92 passing |
| Live page-type verification (10 page types incl. both empty and populated states) | Confirmed correct (§6) |
| Live SQL-query-count investigation + 2 real fixes | Confirmed correct (§5, §6) |
| Live backward compatibility | Confirmed correct (§4) |
| Benchmark Report | Complete (§7, full detail in `BENCHMARKS.md`) |
| Browser-based visual/interaction QA | **Not performed — no browser tool available this session.** See §10. |

---

## 10. Known limitations (disclosed, not silently skipped)

- **No real-browser verification.** This environment has no browser-automation tool (no Playwright/Puppeteer/screenshot capability). Everything that can be verified without one was: JS syntax validation (`node --check`, clean), CSS brace-balance and manual review of every rule, and exhaustive `curl`-based verification of every page's actual rendered HTML/markup contract/SQL cost. What was **not** verified: actual visual appearance (color contrast in practice, layout at real viewport widths, hover-state transitions), and actual interactive behavior (mega-menu hover/tap, mobile-nav open/close, infinite-scroll scrolling-and-appending in a live DOM). The code implementing all of these was written carefully and reviewed for correctness, but "reviewed carefully" is not the same claim as "seen working in a browser." Recommend a manual pass in a real browser (desktop + a real mobile device or emulator) before this ships to production, specifically targeting: mega-menu open/close on both pointer and touch, mobile off-canvas nav, infinite scroll on a real multi-page archive, and a visual pass across the 640/900/1200px breakpoints.
- **Cloudflare Images `avatar` variant is not yet configured** in any environment this session had access to (confirmed: `TUBE_PLAYER_CLOUDFLARE_IMAGES_ACCOUNT_HASH` is empty in staging) — this is a known, pre-existing gap (the `grid_card`/`hero`/`og_image` variants aren't configured either), not something this phase broke. Before production launch, whoever owns the Cloudflare account needs to configure all four variants, `avatar` included.
- **No production deployment has occurred.** Per the user's explicit instruction, this phase stops after tagging; production deployment requires separate, explicit confirmation.

---

## 11. Explicitly out of scope for Phase 13

- **Infinite scroll on the homepage or Trending/Most-Viewed/Latest** — see Design Decision #4. Would require new `tube-search` pagination/caching surface and touches the frozen cache-invalidation design; a real future requirement, not built speculatively.
- **Per-actor/studio video counts on the directory pages** — would require a new bulk-count repository method (`count_videos_for_actor()` is a live, per-actor `COUNT()`; calling it once per row on a 24-item paginated grid would be a real N+1). Directory rows show name + photo + link only.
- **A featured-video flag for the hero** — Design Decision #2; the hero is fully automatic from trending data, no new data model or admin UI.
- **A light/dark toggle** — Design Decision #3; dark-only.
- **Any build tooling (Sass/PostCSS/bundler)** — deliberately not introduced; see the approved plan's CSS-architecture section for the full reasoning (no build tooling exists anywhere else in this project, and introducing one now would be new project-wide scope, not a theme-visual change).

---

## 12. Technical Debt Budget

**Zero debt filed, none carried in.** Checked against the "known, intentional gap between what was implemented and what genuinely production-quality implementation would look like" test:

- The browser-QA limitation (§10) is a **disclosed environmental constraint**, not an implementation shortcut — every check that could be run without a browser was run, and the gap is named specifically rather than silently assumed away. It is not filed as a Debt ADR because it isn't a gap in what was *built*; it's a gap in what could be *verified in this session*. Recommend closing it with a manual QA pass before production traffic, per §10.
- The Cloudflare `avatar` variant not being configured is an **operational task for whoever owns that account**, not an application defect — the code handles its absence gracefully (`ProfileImageHtmlRenderer` returns `''`, the placeholder icon renders instead), verified live.
- Everything else in this phase (the 2 query-count fixes, the PHPStan findings, the `heading_tag`/`profile-avatar.php` design refinements) was found and fixed within this phase's own commits, not deferred.

No Debt ADR filed. `ARCHITECTURE-CHANGELOG.md` gets a phase-table-append entry (§13) — not a frozen-decision change, so it needs no `adr/` ADR under §8.

---

## 13. `ARCHITECTURE.md` §12 update

A new row was added to §12's Implementation Phases table: `| 13 | tube-theme: full production UI (dark theme, hero, mega menu, infinite scroll, actor/studio pages, modern search) — plus minimal additive tube-core/tube-player template tags the presentation layer needed (actor/studio listing + bulk lookup, actor/studio photo rendering). User-commissioned; not part of the original 0–12 roadmap. See PHASE-13.md. |`. `ARCHITECTURE-CHANGELOG.md` logs this as a phase-table append, explicitly noting it uses only already-existing patterns (template tags, additive repository methods, WordPress Page templates) and changes no frozen decision — no ADR required.

---

## 14. Production impact

None. All work happened in the local Docker staging environment. Production was not accessed. Per the user's explicit instruction: this phase stops here, tagged and pushed, and **does not deploy** — production deployment requires separate, explicit confirmation.
