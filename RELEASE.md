# Release 1.0.1

**Status: Production-ready.** Tagged `v1.0.1` on `origin/main`, superseding `v1.0.0`. `v1.0.0` fataled on a clean production deploy — see "1.0.1 hotfix" below — and should not be deployed; deploy `v1.0.1` instead. This is the project's first release — a from-scratch rebuild of a WordPress-based video tube site, replacing a prior installation the original site audit found running nulled/third-party plugin code.

## 1.0.1 hotfix (2026-08-07)

`v1.0.0`'s documented deploy procedure (`docs/DEPLOYMENT.md` §3 step 2) ran a single `composer install --no-dev` at the release root. This project has no shared runtime autoloader by design (`ARCHITECTURE.md` §4 — each of the 6 plugins is independently `composer install`-able via its own `composer.json`; the root `composer.json` is dev tooling only, no `autoload` section), so a release deployed exactly per that documented procedure left every plugin's own `vendor/autoload.php` missing, and every plugin fataled on boot (`Class "Tube_X\Plugin" not found`). This was found deploying the tagged `v1.0.0` release to a clean production VPS — no application code was at fault, and `vendor/` was correctly `.gitignore`d all along (never meant to be committed); the deploy documentation itself was the defect. Full account in `CHANGELOG.md`'s `[1.0.1]` entry, including why 12 phases of review didn't catch it (every local/staging verification ran against a long-lived checkout whose `vendor/` directories already existed, never a genuinely clean one) and how it was verified fixed (reproduced the fatal on live staging, then confirmed the corrected per-plugin `composer install` sequence resolves it). No functional code changed in this patch — only `docs/DEPLOYMENT.md`, `docs/UPGRADE.md`, `ARCHITECTURE.md`'s deploy-sequence line, version bumps, and this changelog/release-notes update.

## What this release is

Six independent WordPress plugins (`tube-core`, `tube-cache`, `tube-player`, `tube-search`, `tube-seo`, `tube-admin`) plus a presentation-only theme (`tube-theme`), built in 12 phases against a frozen architecture (`ARCHITECTURE.md`, Revision 5; `ARCHITECTURE_FREEZE.md`). Full feature history in `CHANGELOG.md`; full evidence for every phase in `PHASE-0.md` through `PHASE-12.md`.

## Confirmed production target

- Single VPS, 3,000–10,000 videos, a few million pageviews/month.
- Redis (object cache, view-counter buffering, rate limiting), MySQL/MariaDB, Cloudflare (Stream, Images, CDN).
- Explicitly **not** built: read replicas, MySQL native partitioning, Elasticsearch/OpenSearch, Kubernetes, message brokers, or any other distributed infrastructure — this target doesn't need them, and building them anyway would have been premature optimization against a scale this deployment doesn't operate at (`ARCHITECTURE_FREEZE.md`'s Deferred Decisions, `PHASE-11.md`).

## Final release verification (Phase 12)

Every item below was re-verified fresh against the tagged commit, not carried forward from memory of an earlier phase:

- **Architecture Drift Report** — clean, all 8 criteria, whole codebase.
- **Implementation Review** — clean; the project's one long-standing, previously-reviewed exception (`ActorRepositoryInterface`/`StudioRepositoryInterface` each having a single implementation, justified since Phase 8 as a genuine cross-plugin boundary rather than a test-fake case) re-confirmed, not new drift.
- **Security review** — no findings. No unprepared queries, no unescaped output, no hardcoded secrets, safe `unserialize()` usage (`allowed_classes: false`).
- **SQL audit** — every query across all 6 plugins reviewed: parameterized, indexed, bounded (or deliberately and documentedly unbounded at a scale this target's real data volume never approaches).
- **Capability/nonce audit** — every `wp-admin` write action gated by both `current_user_can()` and a nonce.
- **REST audit** — both `/tube/v1` routes reviewed: the Cloudflare Stream webhook (HMAC-verified, replay-protected) and the public watch-history endpoint (deliberately unauthenticated by design, self-scoped, input-validated).
- **Migration audit + full rollback drill** — all 10 migrations (`tube-core` 001–009, `tube-search` 001) verified reversible; a full drill rolled `tube-core`'s entire schema down to its floor and back up against a real data clone, confirming structural correctness and zero impact on unrelated tables' data.
- **Benchmark verification** — 3 clean runs, every metric consistent with every prior phase back to Phase 8; the new Phase 12 section in `BENCHMARKS.md` is this release's official performance baseline.
- **Full verification gate** — `phpcs` exit `0`, `phpstan` level `max` `[OK] No errors`, 165/165 unit tests, 84/84 integration tests, live verification against the real staging stack (all public pages, all grid templates, the REST webhook path, the admin screens).
- **Technical debt** — zero undocumented. Every deliberately-accepted gap (`RateLimiter` has no live callers yet; the nightly `index:rebuild` job's own internal per-video query count isn't batched) is documented with its reasoning in `BENCHMARKS.md`'s "Rejected optimizations" sections, not silently carried.

## What changed in this release specifically

Phase 12 made **no functional code changes** — per its own instructions, this was a final-verification and release-preparation phase only. The only file changes are: version bumps (every plugin/theme, `0.1.0` → `1.0.0`), five new production runbooks (`docs/`), this file, `CHANGELOG.md`, `PHASE-12.md`, and a new `BENCHMARKS.md` section. All application behavior is exactly what Phase 11 shipped and verified.

## Operating this release

- **Deploying it**: `docs/DEPLOYMENT.md` — includes the one-time first-production-deployment checklist and the standard per-release deploy sequence.
- **Backing it up / restoring it**: `docs/BACKUP_RESTORE.md`.
- **Upgrading it later**: `docs/UPGRADE.md`.
- **Rolling it back**: `docs/ROLLBACK.md`.
- **Monitoring it**: `docs/MONITORING.md`.

## What's explicitly not in this release

Not a gap — a deliberate, documented boundary. Anything requiring a real functional requirement or benchmark evidence this deployment doesn't have yet stays deferred, per `ARCHITECTURE_FREEZE.md`'s change-control rule (`DEVELOPMENT_RULES.md` §8):

- Read/write MySQL replica routing, MySQL native partitioning, Elasticsearch/OpenSearch, a generic service container, a message broker — all explicit non-goals for this deployment's real scale.
- Fragment/edge caching for the theme (Cloudflare CDN + the existing Redis object cache already cover this target's needs; no benchmark shows it's necessary — `BENCHMARKS.md`'s Phase 11 "Rejected optimizations").
- Batched per-video queries inside the nightly `index:rebuild` CLI job (CLI-only, no page-load latency budget, no benchmark evidence of a real problem at this target's video count).

If production traffic ever proves one of these actually necessary, the path back to reconsidering it is the standard ADR process (`DEVELOPMENT_RULES.md` §8) — a benchmark or a real production issue, not a hunch.

## Project status after this release

Per the explicit instruction this release was built under: **this is the final planned phase.** No Phase 13 begins without a new, explicit instruction to do so.
