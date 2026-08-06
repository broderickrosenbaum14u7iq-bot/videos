# Upgrade Procedure

How to move production from one tagged release to a later one, after the initial 1.0.0 launch. This is the routine case of `docs/DEPLOYMENT.md` §3's deploy sequence — this document adds what's specific to upgrading an already-live site with real data and real traffic, rather than launching for the first time.

## 1. Before upgrading

- [ ] Read the target release's entry in `CHANGELOG.md` in full — know what's changing before it changes.
- [ ] Confirm the target release's own `PHASE-X.md` (or equivalent release report) shows a clean final verification gate: `phpcs`/`phpstan`/unit/integration tests all passing, benchmarks compared against the prior release with no unexplained regression.
- [ ] If the release includes any migration, confirm its `up()` was already dry-run against a production-scale (or realistically-sized synthetic) staging copy, per `ARCHITECTURE.md` §18.4 — staging's own near-empty fixture data does not exercise `ALTER TABLE` locking/duration behavior the way a populated table does.
- [ ] Take a fresh backup immediately before upgrading (`docs/BACKUP_RESTORE.md`) — not a reused older one.
- [ ] Confirm you have a specific rollback target in mind before starting: the exact prior release tag/directory you'd revert to if the upgrade goes wrong (`docs/ROLLBACK.md`).

## 2. Compatibility check specific to this architecture

Because every schema change in this project is required to be reversible and additive-by-default (`ARCHITECTURE_FREEZE.md` #7, expand/contract pattern per `ARCHITECTURE.md` §18.1), most upgrades need no special sequencing beyond the standard deploy steps. Two situations do need extra care:

- **A migration removes or renames something the previous release's code still reads** (a "contract" step of expand/contract, done in a *later* release than the one that added the replacement). Confirm the release notes explicitly call this out — if they do, the previous release must already be fully rolled out (no old code paths still running) before this upgrade, since old code touching a now-gone column/table would break.
- **A REST route changes.** `/tube/v1` is additive-only (`ARCHITECTURE_FREEZE.md` #14) — a genuinely breaking REST change ships as `/tube/v2` alongside the old namespace, never as an in-place change to `/tube/v1`. If a release's notes describe any REST change, confirm it followed this rule; if it didn't, that's a stop-and-ask situation, not something to deploy around.

## 3. Upgrade sequence

Identical to `docs/DEPLOYMENT.md` §3 (new release directory → `composer install --no-dev` → migrate → symlink flip → smoke test → watch), with one addition specific to an already-live site:

- Between the migration step and the symlink flip, if the release's migrations are additive-only (the common case), there is no ordering hazard — the old code (still live until the symlink flips) simply doesn't know about the new columns/tables yet, which is fine, since nothing reads them until the new code is live.
- If a release's migration is **not** purely additive (rare — flagged in its release notes per §2 above), the symlink flip must happen as close to immediately after the migration as operationally possible, to minimize the window where old code runs against a schema it wasn't written for.

## 4. After upgrading

- [ ] Smoke test (`docs/DEPLOYMENT.md` §4).
- [ ] Watch error logs and `docs/MONITORING.md`'s dashboards for at least 15 minutes, longer if the release touched a high-traffic path (view recording, search, the theme's grid templates).
- [ ] Confirm the next scheduled run of every cron job in `docs/DEPLOYMENT.md` §5 succeeds under the new code (`stats:rollup` runs every 5 minutes, so this is usually a short wait, not an overnight one).
- [ ] Once confident the release is stable (a judgment call — the "post-launch" watch period in `docs/DEPLOYMENT.md` §6 is the pattern to follow, scaled down for a routine upgrade rather than the initial launch), the previous release directory can eventually be pruned, but keep the last 5 on disk per `docs/DEPLOYMENT.md` §3 step 10 regardless.

## 5. WordPress core / third-party plugin upgrades

Distinct from this project's own 6-plugin/theme releases. `ARCHITECTURE_FREEZE.md` explicit non-goals rule out depending on third-party SEO/movie/tube/premium plugins entirely, so the only third-party code in play is WordPress core itself and any genuinely-needed utility plugin (e.g. Akismet, already present in `wp-content/plugins/`). Treat a WordPress core upgrade with the same rigor as this project's own releases: staging first, full smoke test, never a same-day production auto-update. `Requires at least: 6.5` (every plugin header) is the floor, not a ceiling — newer core versions are expected to work but should still be staged first.
