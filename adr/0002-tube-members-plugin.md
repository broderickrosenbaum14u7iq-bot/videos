# ADR-0002: `tube-members` — member accounts, authentication, and Google OAuth

Status: Accepted (retroactive)

Date filed: 2026-08-28 (as part of P0 release remediation, BLOCKER-2). The plugin itself was built and shipped earlier, across the same run of feature-development work that produced the Phase 13 production UI and the theme redesign work that followed it — see "Retroactive filing" below for why this ADR postdates the code.

## Frozen decision being changed

`ARCHITECTURE_FREEZE.md`, Frozen Decision #1:

> Six independent plugins (`tube-core`, `tube-cache`, `tube-search`, `tube-player`, `tube-seo`, `tube-admin`) plus a presentation-only theme — no plugin depends on another's internals or database tables directly; only `tube-core` has no plugin dependency.

This ADR (together with ADR-0003 and ADR-0004, filed alongside it) adds `tube-members` to that list, making it seven-plus-two — nine plugins total. The "no plugin depends on another's internals or database tables directly" clause is **not** otherwise weakened: see Impact analysis below for exactly what `tube-members` does and does not depend on.

## Retroactive filing

**This ADR does not precede the code it describes.** `tube-members` was built and shipped without an ADR at the time, which is itself the defect this filing exists to correct — the 2026-08-28 independent Release Readiness Audit found three plugins (`tube-members`, `tube-ads`, `tube-comments`) added after the freeze with no ADR, rollback plan, or impact analysis on record (BLOCKER-2 in that audit), a direct violation of `DEVELOPMENT_RULES.md` §8's "no exceptions" requirement. This document, and its two siblings, are that missing paperwork, written truthfully against the plugin as it actually exists today — not reconstructed as if it had been written first. Where this ADR cannot state an exact original date or quote the original request (that history predates this session's visible context), it says so rather than inventing one. Everything else stated here — the code's structure, its dependencies, its data model, its tests — is verified directly against the current codebase, not recalled.

## Trigger

**New functional requirement.** The project has no member-account system in the frozen Phase 0–13 architecture at all — every frozen decision and every phase document is about the public, anonymous video-browsing experience (`ARCHITECTURE.md` describes `video`/`actor`/`studio`/taxonomies/views/import, never a user-account system). A public video site with comments, likes, and saved-videos features requires *some* account system for those features to mean anything beyond an anonymous per-browser token; that requirement did not exist, and could not have been anticipated, when the freeze was written. No benchmark or production incident is involved.

## Context

The frozen architecture is entirely unauthenticated on the read path (public archives, search, watch pages) and has no concept of a logged-in visitor anywhere in `ARCHITECTURE.md`. WordPress itself ships a complete, battle-tested user/session/role/capability system (`wp_users`, `wp_usermeta`, `wp_set_auth_cookie()`, the roles-and-capabilities API) that every WordPress install already has running, unused, from day one. Building a parallel custom account system instead of using WordPress's own would have meant re-implementing password hashing, session cookies, and role-based access control that WordPress already provides for free and has hardened for two decades.

## Decision

1. **Storage**: WordPress's native `wp_users`/`wp_usermeta` tables, no custom account table. Members are WordPress users on the built-in `subscriber` role — the same role/capability boundary `MemberRoleGuard` already uses to keep members out of `wp-admin` (`edit_posts` is the dividing line: every role above Subscriber has it, Subscriber does not, so no hardcoded role name is needed).
2. **Session handling**: WordPress's own `wp_set_auth_cookie()` / auth-cookie mechanism directly (`AuthSessionService`) — no custom session/token system.
3. **Local auth**: an email/password registration and login flow (`RegistrationController`/`RegistrationService`, `LoginController`/`LoginService`) built on top of `wp_insert_user()`/`wp_authenticate()`, with its own validation, unique-login generation (`UniqueLogin`), and Redis-backed login-attempt rate limiting (`RedisRateLimiter`, shared implementation pattern with `tube-core`/`tube-comments` — see Impact analysis).
4. **OAuth**: an optional "Sign in with Google" flow (`GoogleOAuthController`/`GoogleOAuthClient`), linking a Google identity to a WordPress user by verified email match, admin-configurable via `GoogleSettingsScreen`.
5. **Email verification**: a token-based flow (`EmailVerificationService`/`VerificationTokenCrypto`/`VerificationEmailSender`) gating full account trust, independent of WordPress core's own (unused-here) email-change-confirmation flow.
6. **Profile**: avatar upload (`AvatarService`/`AvatarController`, validated MIME + `getimagesize()` + a 2MB cap, routed through `media_handle_sideload()` — i.e. real WordPress attachments, not a bespoke upload path) and password change (`PasswordController`) for a logged-in member's own account.
7. **Presentation**: a header account renderer (`HeaderAccountRenderer`) and a frontend account page (`Render/templates/account-page.php`, `AccountRouting`), consumed by the theme via `tube_members_*` template tags — the same additive template-tag convention every other plugin already uses to expose functionality to the theme without the theme reaching into plugin internals.

## Alternatives considered

- **Do nothing / no account system.** Rejected: comments, per-user likes/saves, and any future personalization feature require knowing who a visitor is beyond an anonymous per-browser token; the anonymous-visitor engagement path (`tube-core`'s `VisitorToken`) already exists and is deliberately kept as a *separate*, lower-trust path for guests, not replaced.
- **A custom user/session table instead of WordPress core's.** Rejected: WordPress's own user system is free, already present, already hardened, and every WordPress hosting environment already operates around it (backups, admin tooling, security scanners all assume `wp_users` is the source of truth for accounts). A parallel system would duplicate that surface for no benefit and create exactly the kind of "two sources of truth for who a user is" problem the frozen architecture's own principles argue against elsewhere (e.g. Frozen Decision #6 on `wp_postmeta`).
- **A dedicated `member` custom role instead of the built-in `subscriber`.** Considered, rejected as unnecessary: no capability set beyond "logged in, not a publisher" is currently needed, and `MemberRoleGuard`'s own capability-based check (`edit_posts`) already generalizes correctly if a custom role is ever introduced later, without requiring one now.

## Migration plan

No migration from a prior state — this is new functionality with no pre-existing member data to move. No custom database schema at all (see Decision #1); nothing for `wp tube migrate` to run for this plugin specifically (confirmed: `wp-content/plugins/tube-members/migrations/` does not exist). Enabling the plugin is additive: existing anonymous browsing, video playback, and the guest-engagement paths in `tube-core` are unaffected whether or not `tube-members` is active.

## Rollback plan

Deactivating the `tube-members` plugin removes its REST routes, its header/account-page rendering, and its `admin_init` role guard. No schema rollback is needed (no custom tables). Any WordPress users already created via registration remain as ordinary `wp_users` rows (real user accounts, not orphaned plugin data) — deactivating the plugin does not delete them, matching how deactivating any WordPress plugin never deletes core user accounts. Re-activating restores functionality against the same, unchanged `wp_users` data.

## Impact analysis

- **Which plugins' code changes**: none of `tube-core`/`tube-cache`/`tube-search`/`tube-player`/`tube-seo`/`tube-admin` were modified to accommodate `tube-members` — it is purely additive. The theme (`header.php`, `single-video.php`, `functions.php`) consumes `tube-members`' template-tag API (`tube_members_*`) the same way it already consumes every other plugin's template tags.
- **Cross-plugin dependency (the frozen clause this ADR is actually about)**: `tube-members` does not read or write any other plugin's database tables. It has no `use Tube_Core\...`/`use Tube_Comments\...` runtime imports at all (verified by a repo-wide grep — the only `Tube_Core` references in its source are docblock *comments* comparing conventions, not code). It is, correctly, the same "no plugin dependency" shape `tube-core` itself has, just for a different concern.
- **Shared pattern, not shared code**: `tube-members`' `RedisRateLimiter` (login/registration throttling) is a separate implementation from `tube-core`'s and `tube-comments`' own same-named classes — three independent copies of the same pattern rather than one shared library. This is real, acknowledged duplication (already flagged as technical debt, not a release blocker, in the 2026-08-28 audit's findings) — not a hidden coupling, since each copy is self-contained and none of the three plugins imports another's copy.
- **Which frozen decisions have knock-on effects**: none beyond #1 itself. No video/image bytes are stored by this plugin (#5 unaffected — avatars are real WordPress attachments, the same storage class already used for poster images per ADR-0001, not a new byte-storage pattern). `wp_postmeta` is not used for video data by this plugin (#6 — it uses `wp_usermeta` for user data, a different table for a different domain, not a workaround). No WP-Cron (#8) — nothing here is scheduled. Every schema note in #7 is moot (no schema). REST additive-only (#14) — `tube-members`' routes live under its own path, not `/tube/v1`, and add no breaking change to any existing namespace.
- **Performance/scalability**: negligible against the frozen assumptions — authentication is a per-request, not a per-page-view, cost, and WordPress's own user table is designed for far larger member counts than this project's stated 3,000–10,000-video target implies for a comment/engagement audience.
- **Security posture**: audited independently as part of the same 2026-08-28 review that surfaced this governance gap. Two real defects were found and are being remediated in this same P0 pass (an OAuth account-linking issue, CRIT-1; a rate-limiter fail-open behavior, CRIT-2) — recorded here for completeness, not re-litigated in this ADR, since their fixes are separate, already-tracked P0 items with their own commits.

## Outcome

`tube-members` is live in this codebase today, with a passing unit test suite (24/24 at the time of the 2026-08-28 audit) and no PHPCS/PHPStan errors. This ADR's filing is the outcome being recorded: governance documentation now matches the code that was already shipped. Logged in `ARCHITECTURE-CHANGELOG.md`.
