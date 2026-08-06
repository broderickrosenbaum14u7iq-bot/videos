# Rollback Procedure

How to revert a production deploy that's gone wrong. Follows `ARCHITECTURE.md` §18.3 exactly; this document makes it a concrete runbook.

**The one rule that matters most: rollback ordering is always code-then-schema, never the reverse.** Roll the application back to code that doesn't depend on the new schema before reverting the schema itself — or rely on the fact that this project's migrations default to expand/contract, which usually means the old code already tolerates the new schema being present, and a schema rollback isn't needed at all.

## 1. Decide what kind of rollback this is

| Situation | Action |
|---|---|
| New code is broken, but no migration ran (or the migration is purely additive and harmless to leave in place) | **Code rollback only** — §2 below. This is the common case. |
| A migration itself is the problem (e.g. it's causing lock contention, or its `up()` had a real bug) | **Code rollback, then schema rollback** — §2 then §3, in that order, never reversed. |
| Data itself is corrupted or lost (not a code/schema problem) | **Not a rollback — a restore.** See `docs/BACKUP_RESTORE.md` §4. Don't reach for `wp tube migrate down` to "undo" bad data; migrations revert structure, not data. |

## 2. Code rollback

Near-instant, because deployment uses an atomic symlink swap (`docs/DEPLOYMENT.md` §3):

```bash
ln -sfn /www/wwwroot/phimtoico.org/releases/<previous-good-release> \
        /www/wwwroot/phimtoico.org/current
```

This is why `docs/DEPLOYMENT.md` §3 step 10 requires keeping the last 5 release directories on disk — a code rollback is only instant if the previous release's files are still there to point back at.

Immediately after: smoke test (`docs/DEPLOYMENT.md` §4), watch error logs, confirm the specific problem that triggered the rollback is actually gone.

## 3. Schema rollback (only after the code rollback above, only if genuinely needed)

```bash
wp tube migrate status --path=/www/wwwroot/phimtoico.org/current
# Confirm which version the now-live (rolled-back) code actually expects.

wp tube migrate down --plugin=<slug> --to=<version> --path=/www/wwwroot/phimtoico.org/current
```

This is only safe because `ARCHITECTURE.md` §3/`ARCHITECTURE_FREEZE.md` #7 make a working, reviewed `down()` mandatory for every migration — never optional, never skipped at merge time. `PHASE-11.md`/`PHASE-12.md` document this project's own final rollback drill: every migration's `down()` was verified to genuinely, structurally reverse its `up()` against a full clone of real production-shaped data, with unrelated tables' data completely undisturbed.

**Know before running this**: rolling back a migration that `CREATE TABLE`'d something (the common shape — see every `Migration00N` in `wp-content/plugins/{tube-core,tube-search}/migrations/`) drops that table, discarding any data written to it since it was created. This is an inherent property of a genuinely-reversible schema rollback, not a defect — it's why schema rollback is a deliberate, reviewed action taken only when code rollback alone isn't enough, never a routine first response. If the table being dropped is `wp_tube_search_index`, this is low-stakes: `wp tube-search index:rebuild` regenerates it completely afterward. For any other table, know specifically what you're accepting the loss of before running `migrate down`.

`wp tube migrate down` requires `--to` to name an already-registered, valid target version — it cannot roll a plugin back "to nothing." If a genuine full-teardown is ever needed (extremely unlikely outside a full environment rebuild), that's a manual, individually-reviewed action, not a routine rollback step.

## 4. After any rollback

- [ ] Confirm the site is actually healthy (smoke test, `docs/DEPLOYMENT.md` §4) — a rollback that "should have worked" still needs verification, not assumption.
- [ ] Do not immediately re-attempt the failed deploy. Diagnose the actual root cause in staging first (this project's `DEVELOPMENT_RULES.md` §7 Implementation Review discipline applies to fixing a rollback's root cause exactly as it applies to any other change).
- [ ] Document what happened — what broke, why, what the fix will be — before the next deploy attempt. If the root cause traces back to something staging's verification gate should have caught but didn't, that gate itself may need to change (a rare, genuine case for revisiting process, not a routine outcome).
