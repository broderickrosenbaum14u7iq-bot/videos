# Development Rules

This file is the canonical, durable record of every process and quality rule governing implementation of this project. It exists because sessions have no memory of prior conversations — **read this file and ARCHITECTURE.md in full before starting or resuming work on any phase.** Nothing here should ever be treated as "understood" from a prior session; if it isn't written here, it isn't a rule.

---

## 1. Phase discipline

- Implement **exactly one phase at a time**, exactly as defined in `ARCHITECTURE.md` §12 (Implementation Phases). Never skip a phase. Never implement multiple phases together, even partially.
- Before starting a phase's new work, run the Architecture Drift Report (§6 below) against the codebase as it stands from prior phases.
- Finish a phase completely — including its tests, its documentation, its Architecture Regression Review (§5 below), and a Hostile Pre-Commit Review (§7 below) of every commit in it — before starting the next one.
- **Stop and wait for explicit user approval after every phase.** Do not begin the next phase on your own judgment that the previous one looks done.
- Every phase produces a `PHASE-X.md` (e.g. `PHASE-0.md`, `PHASE-1.md`) documenting what was built and the verification evidence for it — not a summary of intent, actual evidence (command output, live checks).
- If a phase needs a follow-up correction after its initial commit (e.g. an audit), document and commit that separately rather than silently amending history — see `PHASE-1-AUDIT.md` for the precedent.

## 2. Code quality

- **Production quality only.** No shortcuts, no `TODO` placeholders, no temporary or stub implementations, no "fill this in later."
- Every database table is created via a migration. Every migration must be **genuinely reversible** — a `down()` that exactly undoes its `up()` — and this must be verified live (apply, roll back, re-apply, inspect the actual schema/data), not just reviewed by reading the code.
- Every public function/method is fully documented (complete docblocks — description, `@param`, `@return`, `@throws` where relevant).
- Follow **WordPress Coding Standards (WPCS) + PSR-12** together. The two rulesets conflict in several well-identified places (tabs vs. spaces, padded vs. unpadded parens, camelCase vs. snake_case method names, several PHPCSExtra "Universal" sniffs); every such conflict and its resolution is documented with reasoning directly in `phpcs.xml` — read it, don't re-derive these from scratch.
- Every plugin must be **independently testable**: its own `composer.json`, its own PSR-4 autoloading, no plugin depending on another plugin's `composer.json`. Anything WordPress- or database-coupled that has meaningful logic worth testing gets that logic isolated behind a small interface (e.g. `SchemaVersionRepositoryInterface`, `HookBusInterface`) so it can be unit-tested with a fake, instead of requiring a full WordPress bootstrap. **An interface is only created when it clears this bar — a realistic second implementation, meaning a real competing implementation or a test fake that will actually be built and used, not vendor-swap speculation** (`ARCHITECTURE.md` §19.1). If nothing will ever realistically implement it a second way, it's a concrete class, not an interface.
- **No generic service container / no service locator.** Dependencies are constructor-injected or obtained via a plugin's own typed, hand-written accessor methods on its bootstrap class (e.g. `Plugin::migration_runner()`, `Plugin::events()`) — never a string- or class-keyed container `get()` call from arbitrary code. See `ARCHITECTURE.md` §19.2 for why this was explicitly considered and rejected, and the concrete trigger for reconsidering it.
- **Bulk writes, not loops.** Code writing multiple rows to a relationship table in response to a single event/save uses one multi-row `INSERT`, never a loop of single-row inserts (`ARCHITECTURE.md` §19.8).
- **PHP 8.3 only.** `declare(strict_types=1)` as the first statement in every PHP file. Full type declarations everywhere.
- **Every change must pass `phpcs` (exit 0) and `phpunit` (all green) before it is committed.** Not "mostly passes" — zero errors, zero warnings, unless a warning is a deliberate, documented, justified exception.
- **Keep backward compatibility with every previous phase.** After each phase's changes, verify live (reactivate the plugin in staging, re-run the previous phase's own checks) that nothing earlier broke — don't just assume additive changes are safe.
- **Testing-architecture checkpoint.** The decision to defer a full `WP_UnitTestCase` integration suite (Phase 1) must be explicitly reconsidered before Phase 5 (import pipeline) or Phase 6 (`tube-player`), whichever comes first (`ARCHITECTURE.md` §19.9) — not left open-ended indefinitely.

## 3. Environment

- **Never modify production** (`root@139.99.96.155:/www/wwwroot/phimtoico.org`). All implementation work happens only in the local Docker staging environment (`docker-compose.yml`, see `PHASE-0.md` for how to bring it up).
- **WP-Cron is never used.** Every background/scheduled task runs via Linux cron invoking WP-CLI directly (`ops/cron/staging.cron`), per `ARCHITECTURE.md` §7. `DISABLE_WP_CRON` is set to `true`.

## 4. Commit discipline

- Commit only when a phase (or an explicitly-scoped follow-up like an audit) is genuinely complete — no partial/WIP commits.
- Write commit messages that explain *why*, not just *what* — a future session with no memory of this conversation should be able to understand the reasoning from `git log` alone.
- Never force-push, never amend a commit that's already been discussed as complete with the user.

## 5. Architecture Regression Review — run before every phase's commit

Before committing any phase, review everything that phase introduced (re-reading the actual changed files fresh, not reusing reasoning from when they were written) and check whether it:

1. violates `ARCHITECTURE.md`
2. duplicates existing code
3. increases coupling
4. breaks dependency inversion
5. introduces hidden technical debt
6. reduces scalability (this project is designed for 500,000+ videos and millions of pageviews)
7. creates future migration problems

**If the review finds anything, fix it before committing** — never commit with a known issue deferred to "later." Include the review as a dedicated section inside that phase's `PHASE-X.md`, with an actual finding for each of the seven criteria (not a bare pass/fail) — name borderline points that were considered and accepted, with the reasoning, the same way other interpretation decisions in this project are documented rather than left implicit.

This step is not optional and is not skipped for any future phase, regardless of how small the phase seems.

## 6. Architecture Drift Report — run before every phase begins

Where §5 checks what a phase is about to *introduce*, right before committing it, this check runs at the opposite end: **before a phase's new work starts**, against the codebase exactly as prior phases left it. Its job is to catch structural drift that accumulates gradually across phases — the kind of thing no single phase's own regression review would flag, because each phase in isolation looked fine.

Verify, against the actual current code (re-read it fresh, don't reuse a prior session's assessment):

1. **No circular dependencies.** No plugin may depend on another plugin that (directly or transitively) depends back on it — `tube-core` in particular must never depend on `tube-player`/`tube-search`/`tube-seo`/`tube-admin`/`tube-cache`. Within a plugin, no two classes may depend on each other.
2. **No service locator pattern.** Classes receive their dependencies through their constructor. `Plugin::instance()->migration_runner()` / `->events()` are the *composition root* — the one place concrete wiring is expected — calling them from `tube-core.php` or from `Plugin` itself is correct. A different class reaching into `Plugin::instance()->something()` from inside its own unrelated logic, instead of having that dependency injected, is the anti-pattern this rule exists to catch.
3. **No hidden singleton growth.** `Plugin::$instance` is the one deliberate, reviewed singleton (the composition root). Any *other* class quietly growing static state (a static cache, a static registry, a `private static` property outside `Plugin`) without that being an explicit, documented architectural decision is a violation.
4. **No God classes.** No single class accumulating unrelated responsibilities. `Plugin.php` is the known watch point (flagged in the Phase 2 regression review) — each phase that adds another accessor to it should keep those accessors thin and symmetric (construct-or-return-cached, nothing more); if `Plugin.php` starts containing real logic instead of wiring, that's the signal to extract.
5. **No duplicated abstractions.** Two different mechanisms solving the same problem (e.g. two different "validate against a known list and throw" implementations) should be one. A small amount of structurally-similar code at a handful of call sites is not automatically a duplicated abstraction — judge whether extracting a shared helper would reduce real complexity or just add a layer of indirection over three lines of code.
6. **No unnecessary interfaces.** Every interface in the codebase (`MigrationInterface`, `SchemaVersionRepositoryInterface`, `HookBusInterface`, ...) must have a real payoff: either more than one real implementation, or a concrete test-fake that's actually used to unit-test something WordPress/database-coupled without WordPress loaded. An interface with exactly one implementation and no test benefit is speculative and should be inlined back into a concrete class.
7. **No premature optimization.** No caching layer, index, denormalization, or complex data structure that isn't justified by an actual requirement already written in `ARCHITECTURE.md` or a concrete, present need. (The Phase 1 audit's `name_idx` addition is the model for what *is* justified: it was required by an explicit, already-approved architecture requirement, not spec work done "just in case.")
8. **No violation of plugin boundaries.** No plugin queries another plugin's database tables directly, calls another plugin's internal (non-public) classes, or reaches past another plugin's documented public API. Every plugin's `composer.json` remains independently installable — no plugin's `composer.json` requires another plugin's package.

**If this report finds anything, refactor it before continuing with the phase's new work** — the phase does not proceed on top of known drift. Include the report as a dedicated section in that phase's `PHASE-X.md`, with an actual finding for each of the eight criteria (not a bare pass/fail), the same way §5's report works.

This step is not optional and is not skipped for any future phase, regardless of how small the phase seems.

## 7. Hostile Pre-Commit Review — run before every commit

§5 asks whether a change fits the architecture. §6 asks whether the accumulated codebase has drifted. This is different from both: **before every commit** (not just phase-completion commits — every commit, including small documentation or fix-up ones), review the actual diff as if it were written by another engineer you have no reason to trust, and whose commit you are inclined to reject. Do not soften findings to be encouraging. Do not stop at the first pass if a meaningful improvement is still available — keep going until there genuinely isn't one.

Check, at minimum:

- **Architecture violations** — does this diff contradict `ARCHITECTURE.md` or an already-adopted `DEVELOPMENT_RULES.md` decision?
- **Unnecessary abstractions** — is there an interface, wrapper, or indirection layer with no real payoff (apply §6.6's "more than one implementation, or a real test-fake" test)?
- **Over-engineering** — is there complexity (configurability, generality, defensive code) serving a need that doesn't exist yet?
- **Under-engineering** — is there a corner cut here that will have to be redone properly later — something that won't hold up under real load, real concurrency, or an edge case this project is explicitly designed for (500,000+ videos, millions of pageviews)?
- **Performance bottlenecks** — anything that will be slow at the scale this project targets, not just at today's near-empty data volume.
- **N+1 queries** — any loop issuing one query per iteration where a single batched query would do.
- **Race conditions** — any read-then-write, check-then-act, or shared-mutable-state sequence that isn't safe under concurrent requests. Matters especially for anything touching view counters, migration state, or cache.
- **Cache issues** — stale-cache risk, missing invalidation, or a cache key that doesn't vary by every dimension it should.
- **Event ordering issues** — any assumption that events fire in a particular order, or that one listener runs before/after another, that WordPress's hook system doesn't actually guarantee.
- **Migration risks** — anything that could lock a large table longer than acceptable, or that's only safe against an empty table, not a populated one.
- **Rollback risks** — does every migration's `down()` genuinely, exactly reverse its `up()` — re-checked here, not just assumed because §2 requires it.
- **Security issues** — unescaped output, unprepared queries, missing capability/nonce checks, input trusted across a privilege boundary.
- **Maintainability problems** — code a different engineer, or the same engineer a year from now, would struggle to safely change.
- **Future scaling problems** — anything that works today but degrades non-linearly as videos, pageviews, plugins, or developers grow.

**If a meaningful improvement is available, make it before committing.** This is not a report filed for later — it's a gate the commit does not pass until nothing meaningful is left to improve. Findings and what was changed as a result belong in that phase's `PHASE-X.md`, the same as §5 and §6.

This step is not optional and is not skipped for any future commit, regardless of how small it seems.

## 8. Session start checklist

Every session, before writing or changing any code:

1. Read `ARCHITECTURE.md` in full (the approved architecture — currently Revision 5).
2. Read this file, `DEVELOPMENT_RULES.md`, in full.
3. Read the most recent `PHASE-X.md` (and any `PHASE-X-AUDIT.md`) to know what's already built and verified.
4. Run `git log --oneline` and compare against what the phase docs claim — the committed state is the source of truth, not this conversation's memory of it.

Do not rely on conversational memory, session continuity, or any Claude memory feature for project rules or project state. If a rule matters beyond the current message, it belongs in this file or in `ARCHITECTURE.md` — not anywhere else.
