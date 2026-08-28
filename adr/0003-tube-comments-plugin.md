# ADR-0003: `tube-comments` — public video comments

Status: Accepted (retroactive)

Date filed: 2026-08-28 (as part of P0 release remediation, BLOCKER-2). See ADR-0002's "Retroactive filing" section for why this postdates the code — the same explanation applies here and is not repeated in full.

## Frozen decision being changed

`ARCHITECTURE_FREEZE.md`, Frozen Decision #1 (the plugin count and, specifically here, its "no plugin depends on another's internals or database tables directly" clause — this ADR's Impact analysis is the one of the three sibling ADRs where that clause needs the closest look, since `tube-comments` is the one new plugin that owns real database tables of its own). Filed alongside ADR-0002 (`tube-members`) and ADR-0004 (`tube-ads`), together taking the plugin count from six to nine.

## Trigger

**New functional requirement.** No comment system of any kind exists in the frozen Phase 0–13 architecture. A public comment feature requires member identity (ADR-0002) to attribute a comment to a real account, and requires its own moderation/anti-spam/reporting surface that has no equivalent anywhere in the frozen design.

## Context

The frozen architecture's data-ownership discussion (`ARCHITECTURE_FREEZE.md`'s "Where a reasonable peer could disagree") explicitly considered and accepted `tube-core` owning *all* data tables, including a hypothetical future comments table, as the default assumption at freeze time — but also explicitly names the alternative ("split... into their own plugin(s)") as one a reasonable peer could choose instead. `tube-comments` takes that alternative: it owns its own schema rather than routing comment data through `tube-core`.

## Decision

1. **Storage**: five tables owned and migrated by `tube-comments` itself, not by `tube-core`: `wp_tube_comments` (root comments + replies, one self-referencing table), `wp_tube_comment_likes`, `wp_tube_comment_reports`, `wp_tube_comment_counters` (denormalized per-video comment counts), `wp_tube_comment_root_locks` (the 24-hour root-comment-lock mechanism). Each has its own `Migration00N...php`, registered with `tube-core`'s shared `MigrationRunner` via its public `migration_runner()->register_source()` accessor (`Plugin.php:170`) — the same shared migration-runner every other plugin's own migrations already register with per Frozen Decision #7 ("migrations self-register per plugin"), not a new integration point invented for this plugin.
2. **Moderation/anti-spam**: `Comments/AntiSpam/{SpamGuard,SpamPolicy,AccountAge,ContentNormalizer}` — rate-limited via the same `RedisRateLimiter` pattern used by `tube-core`/`tube-members` (a separate implementation, not a shared import — see ADR-0002's Impact analysis for the same duplication note, which applies equally here).
3. **REST surface**: `Http/Comment{Create,Delete,Like,List,Mine,Presenter,Replies,Report,Update}Controller` — additive routes, no interaction with the existing `/tube/v1` namespace's own routes.
4. **Video association**: comments reference a video by its WordPress post ID (`video_id` column) — a foreign-key-shaped relationship, not enforced at the database level (no plugin in this project's frozen schema uses real foreign keys; consistent with the existing `tube-core` tables' own convention).
5. **Presentation**: `CommentsSectionRenderer` + `tube_comments_*` template tags, consumed by `single-video.php` the same additive way every other plugin's template tags are consumed.

## Alternatives considered

- **Route comment data through `tube-core`'s own tables instead.** Considered — this was the freeze-time default assumption. Rejected in practice (this is a retroactive ADR describing what was actually built, not a live design choice being made now) on the reasoning the freeze document itself already validated as acceptable: comments are a distinct domain with their own lifecycle (moderation, reports, anti-spam, a 24-hour root-lock policy) that doesn't naturally belong inside `tube-core`'s existing video/actor/studio/views schema, and giving `tube-comments` its own tables keeps that domain's schema changes from ever needing a `tube-core` release.
- **A generic "engagement" plugin covering likes, saves, and comments together.** Rejected: likes and saves are video-level engagement and already live in `tube-core` (`Likes`/`Saves` namespaces, per `tube-core`'s own existing scope); comments are a fundamentally different, moderation-heavy domain. Splitting them keeps `tube-core`'s existing boundary (video-domain data) intact rather than growing it into a second, unrelated concern.

## Migration plan

Additive only — no pre-existing comment data to move. The five `Migration00{1..5}` files create new tables from nothing; `wp tube migrate up` picks them up through the shared runner exactly like any other plugin's migrations, in the numbered order they self-register. No existing table is altered.

## Rollback plan

Code rollback: standard symlink-swap (`ARCHITECTURE.md` §18.3) removes the plugin's REST routes and rendering. Schema rollback: each migration's own `down()` drops its table, in reverse dependency order (locks/reports/likes/counters before the base comments table, since those reference `comment_id`). No other plugin's schema or code depends on `tube-comments`' tables existing, so removing them cannot break anything outside this plugin — the theme's `tube_comments_*` template-tag calls are already designed to no-op gracefully when the plugin is inactive, the same convention `tube-ads`' template tags use (see ADR-0004).

## Impact analysis

- **Which plugins' code changes**: none of the six originally-frozen plugins were modified. The theme calls `tube_comments_*` template tags additively from `single-video.php`, the same pattern used for every other plugin.
- **Cross-plugin dependency**: the one real runtime dependency is the migration-runner registration described in Decision #1 — a call to `tube-core`'s own public, documented accessor, not a reach into `tube-core`'s private implementation or its database tables directly. Comment data itself lives entirely in `tube-comments`-owned tables; `tube-core` has no knowledge of comments at all (confirmed: no `Tube_Comments\` references anywhere in `tube-core`'s source).
- **Which frozen decisions have knock-on effects**: #1's "tube-core owns all data" *assumption* (not a hard rule — the freeze document itself frames it as the chosen default among viable alternatives) does not extend to this new domain; explicitly noted here rather than silently diverging from it. #6 (`wp_postmeta` never used for video data) is unaffected — no video postmeta is touched; comment data lives in dedicated tables, the same pattern #6 itself endorses. #7 (every schema change is a migration with a genuine `down()`) is followed by all five of this plugin's migrations. #14 (REST additive-only) is followed — no existing route was changed.
- **Data-integrity note carried over from the same 2026-08-28 audit that found this governance gap**: deleting a video does not currently cascade-delete its comments/likes/reports (audit finding CRIT-3, downgraded to P1 in that audit's own re-evaluation — no current user-visible symptom, needs a product decision on trash-only vs. cascade-cleanup before a code fix). Recorded here for completeness since it is squarely this plugin's data; not addressed by this ADR or by this P0 remediation pass, which is scoped to the 9 release blockers only.
- **Performance/scalability**: `wp_tube_comment_counters` exists specifically to avoid a `COUNT(*)` over the full comments table on every video-page render — a denormalized-counter pattern already used elsewhere in this project (`wp_tube_video_statistics`). Consistent with the frozen architecture's existing performance conventions, not a new one.
- **Security posture**: audited independently 2026-08-28. No defects specific to comment-ownership/IDOR were found (update/delete correctly enforce server-side ownership); one real gap was found and is separately tracked as a non-P0 finding (a guest-scoped rate-limit bypass via cookie omission, HIGH-6 in that audit) — out of scope for this P0 pass.

## Outcome

`tube-comments` is live in this codebase today, with a passing test suite (39/39 unit tests at the time of the 2026-08-28 audit) and no PHPCS/PHPStan errors. This ADR's filing is the outcome being recorded. Logged in `ARCHITECTURE-CHANGELOG.md`.
