# ADR-0004: `tube-ads` — VAST ad placements and monetization

Status: Accepted (retroactive)

Date filed: 2026-08-28 (as part of P0 release remediation, BLOCKER-2). See ADR-0002's "Retroactive filing" section for why this postdates the code — the same explanation applies here and is not repeated in full.

## Frozen decision being changed

`ARCHITECTURE_FREEZE.md`, Frozen Decision #1 (plugin count). Filed alongside ADR-0002 (`tube-members`) and ADR-0003 (`tube-comments`), together taking the plugin count from six to nine. Of the three, `tube-ads` is the smallest change to the "no plugin depends on another's internals or database tables directly" clause — it introduces no database schema at all (see Decision below), so there is no new table-ownership question to resolve.

## Trigger

**New functional requirement.** Monetization (pre-roll and display ad placements) has no equivalent anywhere in the frozen Phase 0–13 architecture, which is entirely about content delivery and discovery, not revenue. A video site's ad-serving needs — VAST tag configuration, placement rules, frequency capping — are a distinct concern with no natural home in any of the six originally-frozen plugins.

## Context

The frozen architecture's player boundary (`ARCHITECTURE_FREEZE.md`'s Deferred/Flexible sections, and `tube-player`'s own `VideoProviderInterface` vendor-swap boundary) was designed around Cloudflare Stream playback, with no consideration of third-party ad-serving because none was in scope at freeze time. `tube-player` itself was not modified to add ad awareness — `tube-ads` operates as a layer that renders around the player rather than one that reaches into it.

## Decision

1. **Storage**: `wp_options` only, via `Admin/SettingsRepository` (`get_option()`/`update_option()` against a single option key) — no custom database table at all. Settings are a `Placement/AdSettings` value object (`AdType`, `PlacementConfig`, `PrerollConfig`, `PrerollFrequency`, `GlobalScriptConfig`), validated on save by `Admin/SettingsSanitizer`.
2. **Ad delivery protocol**: VAST (Video Ad Serving Template) — an admin-configured VAST tag URL is fetched, parsed, and played client-side (`assets/js/tube-ads-vast.js`, `tube-ads-preroll.js`, `tube-ads-display.js`).
3. **Client-side failure handling**: `AbortController`-based fetch timeouts, `credentials: 'omit'` on the VAST fetch (no cookie leakage to third-party ad servers), a hard cap on VAST-wrapper redirect depth (defends against a malicious or misconfigured wrapper chain), and a `maxDurationSeconds` safety timer — every failure path (timeout, HTTP error, malformed XML, no-fill, a mid-playback error) converges on tearing the ad down and resuming real content playback, never blocking it.
4. **Admin UI**: `Admin/SettingsScreen` + per-concern view partials (`tab-general`, `tab-preroll`, `tab-homepage`, `tab-player`, `tab-advanced`, `tab-other`) under a single settings screen, following the same "one settings screen, tabbed by concern" shape already used elsewhere in this project's admin surfaces.
5. **Presentation**: `Render/PlacementRenderer` + `tube_ads_*` template tags, consumed by the theme the same additive way every other plugin's template tags are consumed — asset enqueueing is page-type-gated (comments/preroll JS only loads on `is_singular('video')`, and only when a placement is actually configured).

## Alternatives considered

- **A third-party ad-management plugin (e.g. a WordPress ad-rotation plugin) instead of a bespoke one.** Rejected for the same reason `ARCHITECTURE_FREEZE.md` already gives for choosing hand-rolled SEO over Yoast/RankMath: this project's rebuild was motivated in part by the security risk of third-party/nulled plugin code, and VAST/pre-roll behavior needs precise control over failure handling (ads must never be able to block content playback) that a general-purpose ad plugin does not specifically guarantee.
- **Server-side VAST proxying/caching instead of a direct client-side fetch.** Not adopted: the admin-configured VAST tag URL is fetched directly by the client. A server-side proxy was not pursued because it adds a request hop and a caching-invalidation question (ad tags are typically time-sensitive/targeted) without a demonstrated need; revisit only if a concrete requirement (e.g. hiding the tag URL, or working around a client-side CORS constraint) actually arises.
- **Storing placement config in a custom table instead of `wp_options`.** Rejected: the configuration is a single small, admin-edited settings object with no per-row query pattern — exactly what `wp_options` is for, and consistent with how every other plugin's small settings surfaces are already stored in this project (footer/Customizer settings, for example, use the equivalent `theme_mod` mechanism for the same reason).

## Migration plan

None needed — no database schema. Enabling the plugin is fully additive: with no placement configured, `tube_ads_*` template tags render nothing and no ad-related JS is enqueued (confirmed: asset enqueueing is gated on a placement actually being active, not just the plugin being active).

## Rollback plan

Deactivating the plugin removes its settings screen and stops all ad rendering/asset enqueueing. `wp_options` rows persist (as they do for any deactivated plugin) but are inert without the plugin's code to read them — reactivating restores the prior configuration exactly. No schema to roll back.

## Impact analysis

- **Which plugins' code changes**: none of the six originally-frozen plugins were modified, including `tube-player` — `tube-ads` renders around the player via its own template tags rather than the player exposing any ad-specific hook.
- **Cross-plugin dependency**: `tube-ads` has no runtime `use Tube_Core\...`/`use Tube_Player\...` imports (confirmed by repo-wide grep — its one `Tube_Core` reference is a docblock comment comparing an enum-backing convention, not a code dependency). It reads no other plugin's database tables (it has none of its own to reach from, or into).
- **Which frozen decisions have knock-on effects**: none beyond #1. No video/image bytes are stored (#5 unaffected — no media handling of any kind in this plugin). No `wp_postmeta`/custom schema (#6/#7 both moot — no schema at all). No WP-Cron (#8). REST additive-only (#14) — this plugin's admin settings use the standard WordPress Settings API, not custom REST routes, so there is no namespace surface to even collide with `/tube/v1`.
- **Performance**: client-side VAST fetches happen only on pages with an active placement and only after user interaction where relevant (pre-roll gates on the actual play click) — no server-side query cost added to any existing page render.
- **Security posture**: audited independently 2026-08-28 and found to be the cleanest of the three new plugins from a security standpoint — genuinely well-engineered failure handling (see Decision #3), no credential/token exposure client-side (there are none to expose — VAST tag URLs are admin-configured, not secret), and ads confirmed unable to block content playback under any tested failure mode.

## Outcome

`tube-ads` is live in this codebase today, with a passing test suite (17/17 unit tests at the time of the 2026-08-28 audit) and no PHPCS/PHPStan errors. This ADR's filing is the outcome being recorded. Logged in `ARCHITECTURE-CHANGELOG.md`.
