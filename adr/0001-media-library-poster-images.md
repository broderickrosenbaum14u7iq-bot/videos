# ADR-0001: WordPress Media Library for manually-managed poster/OG images

Status: Accepted

Date: 2026-08-24

## Frozen decision being changed

`ARCHITECTURE_FREEZE.md`'s Frozen Decisions list, item 5:

> No video/image bytes are ever stored on the WordPress server; only Cloudflare Stream/Images identifiers are persisted, never playback URLs.

This ADR reverses the **image** half of that decision for the admin-selected poster/OG-image override path only. The **video** half is unchanged: video bytes are never stored on the WordPress server, Cloudflare Stream remains the only video host, and playback continues to be constructed from a stored Stream UID at render time (`ARCHITECTURE.md` §2.1, unchanged).

It also reverses `ARCHITECTURE.md` §8.2 ("the image is uploaded through `tube-admin`'s editorial UI and stored in Cloudflare Images... not the local WordPress media library") and resolves Open Decision #5 in `ARCHITECTURE.md` §13 ("Cloudflare Images vs. local media library for custom poster overrides") in favor of the local media library — the opposite of §8.2's original recommendation.

The default poster path (§8.1, Cloudflare Stream thumbnail extraction for a video with no override) is **unchanged** — this ADR only affects the override path.

## Trigger

**New functional requirement**, per `DEVELOPMENT_RULES.md` §8's third permitted trigger. The project owner explicitly requires (2026-08-24): WordPress Media Library as the canonical system for manually-selected/uploaded video poster/OG images, with attachment IDs — not Cloudflare Images IDs — as the persisted reference, and no behind-the-scenes re-upload to Cloudflare Images. This is a direct, explicit instruction from the person with authority to change frozen architecture, not a benchmark or production incident.

## Context

§8.2's original reasoning: at a 500,000+-video target, generating and storing WordPress's default derivative image sizes for every uploaded override would mean millions of physical files on the origin server, so custom poster/OG overrides were routed to Cloudflare Images instead, keeping the "no image bytes on the WordPress server" principle intact for the override case too.

That reasoning no longer matches this project's actual deployment target. `RELEASE.md`'s "Confirmed production target" states **3,000–10,000 videos** on a single VPS — not 500,000+. Even if every single video eventually got a custom poster override (the maximum possible case, far more than realistic), that is at most low tens of thousands of attachment rows plus their WordPress-generated derivative sizes — an ordinary WordPress media library workload, not the millions-of-files scenario §8.2 was actually guarding against. The "500,000+ videos" scale target itself (`ARCHITECTURE.md` §10) remains correct and unchanged for every other subsystem (views, stats, search index, import queue); this ADR does not touch it. It is specifically the override-image byte-storage question where the originally-assumed worst case and the confirmed real deployment target diverge enough to justify reversal.

Separately: Cloudflare Images was never actually configured or exercised end-to-end in any environment this project has run in. `CloudflareImagesUploader`'s own docblock states it is "not live-network-verified... no real Cloudflare Images account exists to test against," and `PHASE-13.md`/`ARCHITECTURE.md` both confirm the account hash has been empty in every environment through v1.1.0. A direct database check of the current staging environment (`wp_tube_video_metadata`, 228 rows) confirms `poster_image_id`/`og_image_id` are `NULL` on every row — the override path has never actually been used to store a real Cloudflare Images ID anywhere this project can inspect.

## Decision

1. `wp_tube_video_metadata.poster_image_id` / `og_image_id` now store **WordPress Media Library attachment IDs** (the `wp_posts.ID` of an `attachment` post), never Cloudflare Images IDs, going forward.
2. The admin uploads or picks an image via WordPress's native media modal (`wp.media()` — supports both "upload from computer" and "select existing library item" in one interface, satisfying both required entry points without custom upload-handling code).
3. `tube-player`'s `ImageHtmlRenderer` resolves an override via `wp_get_attachment_image_url()`/`wp_get_attachment_image_srcset()` instead of `CloudflareImagesUrlBuilder`. If an override ID is set and resolves to a real attachment, it is used and **Cloudflare Stream is never consulted** for that render (confirmed with the project owner: this only applies when an override is actually selected). If no override is set, or the stored ID no longer resolves to a real attachment (e.g. the attachment was deleted from under it), the existing Cloudflare Stream thumbnail-extraction default (§8.1) is used unchanged — this is graceful degradation for a broken reference, not the normal case.
4. `tube-admin`'s `CloudflareImagesUploader`/`PosterUploadService`/`ImageUploaderInterface`/`ImageUploadException` (Phase 10) are removed — their entire reason to exist was uploading to Cloudflare Images, which no longer happens.
5. `tube-player`'s Cloudflare Images plumbing for **actor/studio photos** (`Plugin::images_url_builder()`, `CloudflareImagesUrlBuilder`, `ProfileImageHtmlRenderer`, `ImageSize::Avatar`) is explicitly **out of scope** and unchanged — that is a separate Phase 13 feature this ADR was not asked to touch, and `ARCHITECTURE_FREEZE.md` item 5 still applies to it as originally written.
6. `tube-core`'s `VideoMetadataRepositoryInterface` gains `update_stream_uid()`, and `tube-admin`'s video edit screen gains a manually-editable Cloudflare Stream UID field (a separate, additive functional requirement approved in the same instruction — not itself a frozen-decision reversal, since no frozen decision said the UID had to be immutable after creation; recorded here because it ships in the same change).

## Alternatives considered

- **Do nothing / keep Cloudflare Images.** Rejected: explicitly overridden by the project owner's direct instruction; also the override path has never been exercised in any real environment, so keeping it serves no working functionality today.
- **Reinterpret `poster_image_id`/`og_image_id` in place, same column, no migration.** Considered, and rejected in favor of the migration in §"Migration plan" below: even though this project's own visible data is confirmed empty, the project owner explicitly required not silently reinterpreting data that could exist in an environment this session cannot inspect (production). An in-place reinterpretation with no data-safety step would violate that instruction even if it happens to be harmless in practice.
- **Add entirely new column names** (e.g. `poster_attachment_id`) instead of reusing `poster_image_id`/`og_image_id`. Rejected: the project owner explicitly required `poster_image_id`/`og_image_id` themselves to have "unambiguous semantics as WordPress attachment IDs after this architecture change" — i.e., these are the two canonical field names going forward, not names to retire.
- **Register custom WordPress image sizes** (`add_image_size()`) for grid_card/hero/og_image to get server-cropped derivatives matching the old Cloudflare Images variants exactly. Rejected as out of scope for this ADR: `wp_get_attachment_image_url()`/`wp_get_attachment_image_srcset()` against an explicit `[width, height]` array already produces a working, responsive image without registering new sizes or requiring any upload-time reprocessing of already-uploaded media; exact crop-matching is a presentation refinement, not something either the original requirements or the frozen architecture calls for.

## Migration plan

Schema (see `Migration010SeparateLegacyCloudflareImageIds.php`, `tube-core`):

1. `ALTER TABLE wp_tube_video_metadata RENAME COLUMN poster_image_id TO legacy_cf_poster_image_id` (and the same for `og_image_id` → `legacy_cf_og_image_id`) — preserves any value that might exist in an environment this session cannot inspect (production), untouched and unreinterpreted, under a name that makes its stale meaning explicit.
2. Add new `poster_image_id`/`og_image_id` columns (`BIGINT UNSIGNED NULL`, same type as before) via `dbDelta()` — these start `NULL` for every existing row and are populated only through the new WordPress Media Library flow going forward. No existing row's rendering behavior changes as a result of this migration alone (a `NULL` override was already falling through to the Stream-thumbnail default before this change, and still does after it).
3. The `legacy_cf_*` columns are not read by any application code after this change — they are a preserved historical record only, queryable directly via SQL if anyone ever needs to confirm what a video's old Cloudflare Images override was. Not exposing them through `VideoMetadata`/the repository's public API is deliberate: nothing in this codebase has a legitimate reason to consume a Cloudflare Images ID after this ADR, and adding a read path for them would be exposing dead data through a live interface.
4. Applied via the standard `wp tube migrate up` deploy-time step (`ARCHITECTURE.md` §3) — no maintenance window needed; this is a metadata-only, non-locking change on a table with (confirmed) low row counts (228 in staging; production is expected to be in the thousands per `RELEASE.md`'s stated target, not hundreds of thousands).
5. There is no point mid-migration where the system is inconsistent: `up()` runs the rename and the add-column in sequence inside one migration; a partially-applied state (renamed but not yet re-added) would only exist for the duration of that single migration's `up()` call, which `wp tube migrate up` runs to completion or not at all per migration.

Application-layer migration: no data backfill is needed or attempted. Every video simply has no poster/OG override (as it always effectively did, since Cloudflare Images was never live) until an admin picks one through the new UI.

## Rollback plan

Code rollback: standard symlink-swap to the previous release (`ARCHITECTURE.md` §18.3) — reverts every application file this ADR touches.

Schema rollback: `Migration010SeparateLegacyCloudflareImageIds::down()` drops the new `poster_image_id`/`og_image_id` columns (safe — they only ever contain WordPress attachment IDs written after this ADR shipped, verified live as part of this change) and renames `legacy_cf_poster_image_id`/`legacy_cf_og_image_id` back to `poster_image_id`/`og_image_id`, restoring the exact pre-migration column shape and any preserved legacy data. Verified live (apply → roll back → re-apply → inspect schema) as required by `DEVELOPMENT_RULES.md` §2.

Rollback ordering is code-then-schema, per `ARCHITECTURE.md` §18.3 — roll the application code back first (so nothing is calling `update_stream_uid()`/reading WP-attachment-ID semantics against the old schema), then roll the migration back.

## Impact analysis

- **`tube-core`**: `VideoMetadataRepository`/`VideoMetadataRepositoryInterface`/`VideoMetadata` docblocks updated to describe attachment-ID semantics; new `update_stream_uid()` method; new migration. No change to `VideoImporter`, the Stream webhook handler, or any other `tube-core` subsystem.
- **`tube-player`**: `ImageHtmlRenderer` no longer depends on `CloudflareImagesUrlBuilder`; `Plugin::image_renderer()` wiring changes accordingly. `Plugin::images_url_builder()`/`ProfileImageHtmlRenderer`/`ImageSize::Avatar` (actor/studio photos) are unaffected — confirmed out of scope.
- **`tube-admin`**: `VideoDetailsScreen`/`views/edit.php` gain a Stream UID field and a Media Library picker; `CloudflareImagesUploader`/`PosterUploadService`/`ImageUploaderInterface`/`ImageUploadException` and their tests are removed.
- **`tube-seo`**: `SeoHead::resolve_video()`, `SitemapGenerator::build_entry()`, and (indirectly, via the `$image_url` both pass it) `VideoObjectBuilder` previously bypassed the poster/OG override entirely (a pre-existing gap independent of this ADR, found during investigation) — fixed in the same change so all three now honor `og_image_id` consistently with the theme's own `tube_player_get_image_html()` path, rather than leaving that inconsistency in place under a new storage backend.
- **Phases affected**: Phase 6 (`tube-player` image rendering), Phase 8 (theme, unaffected — it only calls the unchanged `tube_player_get_image_html()`/`tube_player_get_embed_html()` template-tag signatures), Phase 10 (`tube-admin`'s custom-poster upload UI, replaced), Phase 13 (unaffected — actor/studio photo rendering is explicitly out of scope). Backward compatibility verified live per `DEVELOPMENT_RULES.md` §2 (see `PHASE-14.md`... — recorded in this change's own verification, not a new phase number, since this is a corrective ADR-driven change, not a new roadmap phase).
- **Other frozen decisions touched**: none besides item 5 (this ADR). `wp_postmeta` is still never used for video data (item 6) — the WP attachment ID is stored in `wp_tube_video_metadata`, a dedicated table, exactly as before; only the *meaning* of the stored integer changed, not where it's stored.
- **Performance/scalability**: negligible. WordPress's own default-generated derivative sizes for a low-thousands-of-videos catalog (per the confirmed production target) is ordinary media-library usage, far below any scale concern this architecture's 500,000+-video assumptions were written to guard against for view/stats/search/import data (which are unaffected by this ADR).

## Outcome

Implemented 2026-08-24, same session as this ADR. See `ARCHITECTURE-CHANGELOG.md`'s corresponding entry for the summary and the session's own final report for verification evidence (tests, static analysis, live staging checks).

## Addendum (2026-08-25): the Cloudflare Stream thumbnail default is removed, not just the override's fallback

**Trigger**: live manual browser testing surfaced that videos with no WordPress Media Library poster set were still rendering a Cloudflare Stream–extracted thumbnail — correct per this ADR's original §"Decision" item 3 (the default path, §8.1, was explicitly left unchanged), but the project owner explicitly reversed that too (2026-08-25): "I do NOT want Cloudflare Stream thumbnails used as poster images anymore... If `poster_image_id` is absent, render a neutral/default placeholder state or no image according to the theme."

**What changes from the original decision above**: item 3's second sentence — "If no override is set, or the stored ID no longer resolves to a real attachment... the existing Cloudflare Stream thumbnail-extraction default (§8.1) is used unchanged" — no longer holds. There is now exactly one image source, in every context (theme poster/hero, click-to-load player poster, OG/Twitter meta tags, JSON-LD `thumbnailUrl`, XML sitemap `<video:thumbnail_loc>`): the WordPress Media Library attachment referenced by `poster_image_id`/`og_image_id`. A missing or unresolvable attachment now behaves identically whether the field was never set or points at a deleted attachment — both render nothing (`ImageHtmlRenderer::resolve_urls()` returns `src: null`), not a Cloudflare-generated substitute. `ARCHITECTURE.md` §8.1's Cloudflare Stream thumbnail-extraction capability itself is not deleted from the codebase (`VideoProviderInterface::thumbnail_url()`/`CloudflareStreamProvider::thumbnail_url()` remain, still unit-tested, as the frozen §19.5 vendor-swap boundary — they simply have no caller left in application code).

**Consequence for `tube-seo`**: Google's video sitemap protocol requires a real `<video:thumbnail_loc>`. A video with real, `Ready` Cloudflare Stream metadata but no WordPress OG-image override now has no valid value to publish there, so `SitemapGenerator::build_entries()` omits it from the sitemap entirely — the same "not ready to publish" treatment already applied to a video with no metadata row at all. JSON-LD's `thumbnailUrl` and the `og:image`/`twitter:image` meta tags are omitted (not fabricated as an empty string) under the same condition, mirroring how `duration`/`video:duration` are already omitted rather than fabricated as `0` when genuinely unknown.

**Rollback**: pure application-code revert (no schema change in this addendum) — restore `ImageHtmlRenderer`'s Cloudflare Stream fallback branch and its `VideoProviderInterface $stream_provider` constructor dependency.
